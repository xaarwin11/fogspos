<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($input['order_id'] ?? 0);
$reason = $input['reason'] ?? 'No reason provided';
$pin = $input['pin'] ?? '';

if (!$order_id) { echo json_encode(['success' => false, 'error' => 'No order ID']); exit; }

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

    $o_stmt = $mysqli->prepare("SELECT grand_total, status FROM orders WHERE id = ?");
    $o_stmt->bind_param('i', $order_id);
    $o_stmt->execute();
    $order = $o_stmt->get_result()->fetch_assoc();
    $o_stmt->close();

    if (!$order) throw new Exception("Order not found.");
    if ($order['status'] !== 'paid') throw new Exception("Only paid orders can be refunded.");

    $refund_amount = (float)$order['grand_total'];

    $r_stmt = $mysqli->prepare("INSERT INTO refunds (order_id, manager_id, reason, total_amount, created_at) VALUES (?, ?, ?, ?, NOW())");
    $r_stmt->bind_param('iisd', $order_id, $manager_id, $reason, $refund_amount);
    $r_stmt->execute();
    $refund_id = $mysqli->insert_id;
    $r_stmt->close();

    $ri_stmt = $mysqli->prepare("INSERT INTO refund_items (refund_id, order_item_id, qty, amount) SELECT ?, id, quantity, line_total FROM order_items WHERE order_id = ?");
    $ri_stmt->bind_param('ii', $refund_id, $order_id);
    $ri_stmt->execute();
    $ri_stmt->close();

    // PURE RELATIONAL: Just flag as refunded. No legacy void data!
    $u_order = $mysqli->prepare("UPDATE orders SET status = 'refunded' WHERE id = ?");
    $u_order->bind_param('i', $order_id);
    $u_order->execute();
    $u_order->close();
    
    if ($refund_amount > 0) {
        $neg_amount = -$refund_amount;
        $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, 'cash', ?, 0, ?, NOW())");
        $p_stmt->bind_param('idi', $order_id, $neg_amount, $manager_id);
        $p_stmt->execute();
        $p_stmt->close();
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['refund_id' => $refund_id, 'total_refund' => $refund_amount, 'reason' => $reason]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'full_order_refund', 'order', ?, ?, ?, NOW())");
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