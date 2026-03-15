<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)$input['order_id'];
$items_to_refund = $input['items'] ?? []; // Array of {id, amount}
$reason = $input['reason'] ?? 'No reason provided';
$pin = $input['pin'] ?? '';

if (empty($items_to_refund)) { echo json_encode(['success' => false, 'error' => 'No items selected.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    // 1. Verify Manager PIN
    $stmt = $mysqli->prepare("SELECT id, role_id, passcode FROM users WHERE is_active = 1");
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $manager_id = null;
    foreach ($users as $u) {
        if (password_verify($pin, $u['passcode'])) {
            if (in_array((int)$u['role_id'], [1, 2])) { 
                $manager_id = $u['id']; break;
            }
        }
    }
    if (!$manager_id) throw new Exception("Invalid PIN or insufficient privileges.");

    $total_refund_amount = 0;
    
    // 2. Loop through and Void the selected items
    $u_item = $mysqli->prepare("UPDATE order_items SET line_total = 0, discount_note = CONCAT(COALESCE(discount_note, ''), ' [REFUNDED]') WHERE id = ?");
    
    foreach ($items_to_refund as $item) {
        $order_item_id = (int)$item['id'];
        $amount = (float)$item['amount'];
        $total_refund_amount += $amount;
        
        $u_item->bind_param('i', $order_item_id);
        $u_item->execute();
    }
    $u_item->close();

    // 3. Deduct from the Grand Total
    $u_order = $mysqli->prepare("UPDATE orders SET grand_total = grand_total - ? WHERE id = ?");
    $u_order->bind_param('di', $total_refund_amount, $order_id);
    $u_order->execute();
    $u_order->close();

    // 4. Inject Negative Payment to fix the Cash Drawer
    $neg_amount = -$total_refund_amount;
    $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, 'cash', ?, 0, ?, NOW())");
    $p_stmt->bind_param('idi', $order_id, $neg_amount, $manager_id);
    $p_stmt->execute();
    $p_stmt->close();

    // 5. Audit Trail
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['items_count' => count($items_to_refund), 'total_refund' => $total_refund_amount, 'reason' => $reason]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'batch_item_refund', 'order', ?, ?, ?, NOW())");
    $log_stmt->bind_param('iiss', $manager_id, $order_id, $details, $ip_address);
    $log_stmt->execute();
    $log_stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>