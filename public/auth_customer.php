<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

$input = json_decode(file_get_contents('php://input'), true);
$phone = trim($input['phone'] ?? '');
$pin = trim($input['pin'] ?? '');

if (empty($phone) || empty($pin)) {
    echo json_encode(['success' => false, 'error' => 'Phone and PIN required.']); exit;
}

try {
    $mysqli = get_db_conn();
    $stmt = $mysqli->prepare("SELECT id, name, passcode FROM verified_customers WHERE phone = ? AND is_active = 1");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($cust = $res->fetch_assoc()) {
        if (password_verify($pin, $cust['passcode'])) {
            // Success! Create a secure customer session
            session_regenerate_id(true);
            $_SESSION['customer_id'] = $cust['id'];
            $_SESSION['customer_name'] = $cust['name'];
            $_SESSION['customer_phone'] = $phone;
            $_SESSION['public_csrf'] = bin2hex(random_bytes(32));
            
            echo json_encode(['success' => true]); exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid Phone or PIN. Please visit the cafe to register.']);
} catch (Exception $e) {
    error_log("Customer Login Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error.']);
}
?>