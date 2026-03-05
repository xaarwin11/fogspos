<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }

$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403); echo json_encode(['success' => false, 'error' => 'Security token invalid.']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;

if ($order_id) {
    try {
        $mysqli = get_db_conn();
        
        // SECURITY CAMERA FOR VOIDED ORDERS
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $details = json_encode(['action' => 'cart_cleared']);
        $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'order_voided', 'order', ?, ?, ?, NOW())");
        $log_stmt->bind_param('iiss', $_SESSION['user_id'], $order_id, $details, $ip);
        $log_stmt->execute();
        $log_stmt->close();
        
        // THE FIX: Soft Delete! Update status to 'voided' and save who did it.
        $stmt = $mysqli->prepare("UPDATE orders SET status = 'voided', voided_by = ?, void_reason = 'Cart Cleared by Cashier', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ii', $_SESSION['user_id'], $order_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
} else {
    echo json_encode(['success' => true]);
}
?>