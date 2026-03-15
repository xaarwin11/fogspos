<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($input['order_id'] ?? 0);
$reason = $input['reason'] ?? 'Voided / Cleared Cart';
$pin = $input['pin'] ?? '';

if (!$order_id) { echo json_encode(['success' => false, 'error' => 'No order ID provided.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    // 1. Verify Manager / Cashier
    $voided_by_id = null;
    if (!empty($pin)) {
        $stmt = $mysqli->prepare("SELECT id, passcode FROM users WHERE is_active = 1");
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($users as $u) {
            if (password_verify($pin, $u['passcode'])) { $voided_by_id = $u['id']; break; }
        }
        if (!$voided_by_id) throw new Exception("Invalid PIN.");
    } else if (!empty($_SESSION['user_id'])) {
        $voided_by_id = (int)$_SESSION['user_id'];
    } else {
        throw new Exception("Unauthorized. Please provide a PIN or log in.");
    }

    // 2. Ensure Order is Open
    $o_stmt = $mysqli->prepare("SELECT status FROM orders WHERE id = ?");
    $o_stmt->bind_param('i', $order_id);
    $o_stmt->execute();
    $order = $o_stmt->get_result()->fetch_assoc();
    $o_stmt->close();

    if (!$order) throw new Exception("Order not found.");
    if ($order['status'] !== 'open') throw new Exception("Only OPEN/UNPAID orders can be voided.");

    // 3. Create Relational Void Header
    $v_stmt = $mysqli->prepare("INSERT INTO voids (order_id, manager_id, reason, created_at) VALUES (?, ?, ?, NOW())");
    $v_stmt->bind_param('iis', $order_id, $voided_by_id, $reason);
    $v_stmt->execute();
    $void_id = $mysqli->insert_id;
    $v_stmt->close();

    // 4. Auto-copy all order items into void_items
    $vi_stmt = $mysqli->prepare("INSERT INTO void_items (void_id, product_name, quantity, amount) SELECT ?, product_name, quantity, line_total FROM order_items WHERE order_id = ?");
    $vi_stmt->bind_param('ii', $void_id, $order_id);
    $vi_stmt->execute();
    $vi_stmt->close();

    // 5. Update main order (NO void_reason or voided_by logic here!)
    $u_order = $mysqli->prepare("UPDATE orders SET status = 'voided', updated_at = NOW() WHERE id = ?");
    $u_order->bind_param('i', $order_id);
    $u_order->execute();
    $u_order->close();

    // 6. Audit Trail
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['void_id' => $void_id, 'reason' => $reason]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'order_voided', 'order', ?, ?, ?, NOW())");
    $log_stmt->bind_param('iiss', $voided_by_id, $order_id, $details, $ip_address);
    $log_stmt->execute();
    $log_stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>