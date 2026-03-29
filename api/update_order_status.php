<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// Security check: Only logged-in staff can change statuses
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = isset($input['order_id']) ? (int)$input['order_id'] : null;
$new_status = isset($input['status']) ? trim(strtolower($input['status'])) : null;

$allowed_statuses = ['open', 'preparing', 'ready', 'paid', 'voided', 'refunded'];

if (!$order_id || !in_array($new_status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID or status.']);
    exit;
}

try {
    $mysqli = get_db_conn();
    
    $stmt = $mysqli->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $order_id);
    $stmt->execute();
    
    // Log the action
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $details = json_encode(['action' => 'status_change', 'new_status' => $new_status]);
    $log = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'order_updated', 'order', ?, ?, ?, NOW())");
    $log->bind_param("iiss", $_SESSION['user_id'], $order_id, $details, $ip);
    $log->execute();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>