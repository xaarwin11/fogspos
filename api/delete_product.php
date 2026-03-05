<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit;
}

$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403); echo json_encode(['success' => false, 'error' => 'Security token invalid.']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Product ID missing']); exit;
}

try {
    $mysqli = get_db_conn();
    
    $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM order_items WHERE product_id = ?");
    $check_stmt->bind_param('i', $id);
    $check_stmt->execute();
    $sold_count = $check_stmt->get_result()->fetch_assoc()['count'];

    if ($sold_count > 0) {
        $stmt = $mysqli->prepare("UPDATE products SET available = 0, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        
        $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details) VALUES (?, 'product_updated', 'product', ?, ?)");
        $details = json_encode(['status' => 'deactivated', 'reason' => 'has_sales_history']);
        $log_stmt->bind_param('iis', $_SESSION['user_id'], $id, $details);
        $log_stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Product deactivated to preserve sales records.']);
    } else {
        $stmt = $mysqli->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        // --- NEW: LOG PERMANENT DELETION ---
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, ip_address, created_at) VALUES (?, 'product_deleted', 'product', ?, ?, NOW())");
        $log_stmt->bind_param('iis', $_SESSION['user_id'], $id, $ip);
        $log_stmt->execute();
        // -----------------------------------

        echo json_encode(['success' => true, 'message' => 'Product removed permanently.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'An error occurred processing this request.']);
}
?>