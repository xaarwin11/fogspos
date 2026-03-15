<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)$input['order_id'];
$items_to_refund = $input['items'] ?? []; 
$reason = $input['reason'] ?? 'No reason provided';
$pin = $input['pin'] ?? '';

if (empty($items_to_refund)) { echo json_encode(['success' => false, 'error' => 'No items selected.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare("SELECT id, role_id, passcode FROM users WHERE is_active = 1");
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $manager_id = null;
    foreach ($users as $u) {
        if (password_verify($pin, $u['passcode'])) {
            if (in_array((int)$u['role_id'], [1, 2])) { $manager_id = $u['id']; break; }
        }
    }
    if (!$manager_id) throw new Exception("Invalid PIN or privileges.");

    $total_refund_amount = 0;
    foreach ($items_to_refund as $item) { $total_refund_amount += (float)$item['amount']; }

    // 1. Create Master Refund Record
    $r_stmt = $mysqli->prepare("INSERT INTO refunds (order_id, manager_id, reason, total_amount, created_at) VALUES (?, ?, ?, ?, NOW())");
    $r_stmt->bind_param('iisd', $order_id, $manager_id, $reason, $total_refund_amount);
    $r_stmt->execute();
    $refund_id = $mysqli->insert_id;
    $r_stmt->close();

    // 2. Create Refund Items
    $ri_stmt = $mysqli->prepare("INSERT INTO refund_items (refund_id, order_item_id, qty, amount) VALUES (?, ?, ?, ?)");
    foreach ($items_to_refund as $item) {
        $order_item_id = (int)$item['id'];
        $qty_refunded = (int)$item['qty'];
        $amount = (float)$item['amount'];
        $ri_stmt->bind_param('iiid', $refund_id, $order_item_id, $qty_refunded, $amount);
        $ri_stmt->execute();
    }
    $ri_stmt->close();

    // 3. PURE RELATIONAL: Only update the financial totals on the main order!
    $u_order = $mysqli->prepare("UPDATE orders SET grand_total = grand_total - ?, subtotal = subtotal - ? WHERE id = ?");
    $u_order->bind_param('ddi', $total_refund_amount, $total_refund_amount, $order_id);
    $u_order->execute();
    $u_order->close();

    $neg_amount = -$total_refund_amount;
    $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, 'cash', ?, 0, ?, NOW())");
    $p_stmt->bind_param('idi', $order_id, $neg_amount, $manager_id);
    $p_stmt->execute();
    $p_stmt->close();

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['refund_id' => $refund_id, 'total' => $total_refund_amount]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'batch_item_refund', 'order', ?, ?, ?, NOW())");
    $log_stmt->bind_param('iiss', $manager_id, $order_id, $details, $ip);
    $log_stmt->execute();
    $log_stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>