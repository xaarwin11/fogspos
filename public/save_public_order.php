<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

$headers = getallheaders();
$token = $headers['X-CSRF-Token'] ?? '';
if (!hash_equals($_SESSION['public_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'error' => 'Security Error']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mysqli = get_db_conn(); 

try {
    $mysqli->begin_transaction();

    $ref = "WEB-" . strtoupper(bin2hex(random_bytes(2)));
    $cust_name = $_SESSION['customer_name'] ?? 'Guest';
    $cust_phone = $_SESSION['customer_phone'] ?? 'No Phone';
    $cust_info = $cust_name . " (" . $cust_phone . ")";
    
    // Format SC/PWD details for the barista to review
    $discount_note = '';
    if (!empty($input['sc_pwd'])) {
        $discount_note = "PENDING SC/PWD: " . $input['sc_pwd']['type'] . " - " . $input['sc_pwd']['name'] . " (" . $input['sc_pwd']['id_num'] . ")";
        // Note: The base64 image ($input['sc_pwd']['image']) could be saved to the database here 
        // if you add an `id_image_data` LONGTEXT column to your `orders` table.
    }

    $stmt = $mysqli->prepare("INSERT INTO orders (reference, order_type, customer_name, status, discount_note, created_at) VALUES (?, 'takeout', ?, 'open', ?, NOW())");
    $stmt->bind_param("sss", $ref, $cust_info, $discount_note);
    $stmt->execute();
    $order_id = $mysqli->insert_id;

    $item_stmt = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, quantity, base_price) VALUES (?, ?, ?, ?)");
    foreach ($input['items'] as $item) {
        $qty = (int)$item['qty'];
        $item_stmt->bind_param("iiid", $order_id, $item['id'], $qty, $item['price']);
        $item_stmt->execute();
    }

    $mysqli->commit();
    echo json_encode(['success' => true, 'order_id' => $order_id]);

} catch (Exception $e) {
    $mysqli->rollback();
    error_log("Public Order Error: " . $e->getMessage()); 
    echo json_encode(['success' => false, 'error' => 'Server busy. Please try again.']);
}
?>