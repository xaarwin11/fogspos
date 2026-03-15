<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure the user is logged in
if (empty($_SESSION['user_id'])) { 
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); 
    exit; 
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($input['order_id'] ?? 0);
$manager_id = (int)$_SESSION['user_id']; // The currently logged-in admin/manager

if (!$order_id) { echo json_encode(['success' => false, 'error' => 'No order ID provided.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    // 1. Ensure the order is actually OPEN
    $o_stmt = $mysqli->prepare("SELECT status FROM orders WHERE id = ?");
    $o_stmt->bind_param('i', $order_id);
    $o_stmt->execute();
    $order = $o_stmt->get_result()->fetch_assoc();
    $o_stmt->close();

    if (!$order) throw new Exception("Order not found.");
    if ($order['status'] !== 'open') throw new Exception("Only OPEN orders can be voided this way.");

    // 2. Mark it as Voided and track WHO voided it
    $void_reason = "Voided directly from Dashboard";
    $u_order = $mysqli->prepare("UPDATE orders SET status = 'voided', void_reason = ?, voided_by = ? WHERE id = ?");
    $u_order->bind_param('sii', $void_reason, $manager_id, $order_id);
    $u_order->execute();
    $u_order->close();

    // 3. Clear all related items so they don't get stuck in the Kitchen Display
    $mysqli->query("DELETE FROM order_items WHERE order_id = $order_id");
    
    // 4. Audit Trail Logging
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['reason' => 'Dashboard Void']);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'order_voided', 'order', ?, ?, ?, NOW())");
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