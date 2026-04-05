<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

// Security: CSRF Protection
$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo json_encode(['success' => false, 'error' => 'Security token invalid.']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;
$new_table_id = !empty($input['new_table_id']) ? (int)$input['new_table_id'] : null;

if (!$order_id || !$new_table_id) {
    echo json_encode(['success' => false, 'error' => 'Missing data.']); exit;
}

try {
    $mysqli = get_db_conn();
    
    // 1. Transfer the order to the new table (No longer blocking occupied tables!)
    $upd = $mysqli->prepare("UPDATE orders SET table_id = ? WHERE id = ?");
    $upd->bind_param('ii', $new_table_id, $order_id);
    $upd->execute();
    $upd->close();

    // 2. Get the new table number to update the screen
    $t = $mysqli->query("SELECT table_number FROM tables WHERE id = $new_table_id")->fetch_assoc();

    // 3. AUDIT LOG FOR TRANSFERRED TABLES
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['action' => 'table_transfer', 'new_table' => $t['table_number']]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'order_updated', 'order', ?, ?, ?, NOW())");
    $log_stmt->bind_param('iiss', $_SESSION['user_id'], $order_id, $details, $ip);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode(['success' => true, 'new_table_name' => 'Table ' . $t['table_number']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>