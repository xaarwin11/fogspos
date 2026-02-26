<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// Check Security Token
$headers = getallheaders();
$token = $headers['X-CSRF-Token'] ?? '';
if (!hash_equals($_SESSION['public_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'error' => 'Security Error']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mysqli = get_db_conn(); // Uses your production-ready DB connection

try {
    $mysqli->begin_transaction();

    // 1. Create the Master Order record
    // We mark it as 'takeout' and 'open' (unpaid) so staff can process it in the shop
    $ref = "WEB-" . strtoupper(bin2hex(random_bytes(2)));
    $cust_info = $input['name'] . " (" . $input['phone'] . ")";
    
    $stmt = $mysqli->prepare("INSERT INTO orders (reference, order_type, customer_name, status, created_at) VALUES (?, 'takeout', ?, 'open', NOW())");
    $stmt->bind_param("ss", $ref, $cust_info);
    $stmt->execute();
    $order_id = $mysqli->insert_id;

    // 2. Add the items
    $item_stmt = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, quantity, base_price) VALUES (?, ?, 1, ?)");
    foreach ($input['items'] as $item) {
        $item_stmt->bind_param("iid", $order_id, $item['id'], $item['price']);
        $item_stmt->execute();
    }

    $mysqli->commit();
    echo json_encode(['success' => true, 'order_id' => $order_id]);

} catch (Exception $e) {
    $mysqli->rollback();
    error_log("Public Order Error: " . $e->getMessage()); // Logs to your error.log
    echo json_encode(['success' => false, 'error' => 'Server busy. Please try again.']);
}