<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'login';
$phone = trim($input['phone'] ?? '');
$pin = trim($input['pin'] ?? '');

if (empty($phone) || empty($pin)) {
    echo json_encode(['success' => false, 'error' => 'Phone and PIN required.']); exit;
}

try {
    $mysqli = get_db_conn();

    if ($action === 'register') {
        $name = trim($input['name'] ?? '');
        $address = trim($input['address'] ?? '');
        if (empty($name)) { echo json_encode(['success' => false, 'error' => 'Name is required for registration.']); exit; }

        $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
        
        // Use INSERT IGNORE or handle duplicate phone number errors
        $stmt = $mysqli->prepare("INSERT INTO verified_customers (phone, name, passcode, address, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("ssss", $phone, $name, $hashed_pin, $address);
        
        if ($stmt->execute()) {
            $_SESSION['customer_id'] = $mysqli->insert_id;
            $_SESSION['customer_name'] = $name;
            $_SESSION['customer_phone'] = $phone;
            $_SESSION['public_csrf'] = bin2hex(random_bytes(32));
            echo json_encode(['success' => true]); exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Phone number already registered. Please login.']); exit;
        }
    } 
    else {
        // Login Logic
        $stmt = $mysqli->prepare("SELECT id, name, passcode FROM verified_customers WHERE phone = ? AND is_active = 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($cust = $res->fetch_assoc()) {
            if (password_verify($pin, $cust['passcode']) || $pin === $cust['passcode']) {
                session_regenerate_id(true);
                $_SESSION['customer_id'] = $cust['id'];
                $_SESSION['customer_name'] = $cust['name'];
                $_SESSION['customer_phone'] = $phone;
                $_SESSION['public_csrf'] = bin2hex(random_bytes(32));
                echo json_encode(['success' => true]); exit;
            }
        }
        echo json_encode(['success' => false, 'error' => 'Invalid Phone or PIN.']);
    }
} catch (Exception $e) {
    error_log("Customer Auth Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error.']);
}
?>