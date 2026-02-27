<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// Only logged-in staff can register new customers!
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Staff only.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$pin = trim($input['pin'] ?? '');

if (empty($name) || empty($phone) || empty($pin)) {
    echo json_encode(['success' => false, 'error' => 'All fields are required.']);
    exit;
}

if (strlen($pin) < 4) {
    echo json_encode(['success' => false, 'error' => 'PIN must be at least 4 digits.']);
    exit;
}

try {
    $mysqli = get_db_conn();
    
    // THE MAGIC: Hash the PIN using bcrypt before saving it!
    $hashed_pin = password_hash($pin, PASSWORD_DEFAULT);
    
    $stmt = $mysqli->prepare("INSERT INTO verified_customers (name, phone, passcode) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $name, $phone, $hashed_pin);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Customer registered successfully!']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not save customer.']);
    }
} catch (mysqli_sql_exception $e) {
    // Check for duplicate phone numbers
    if ($e->getCode() == 1062) {
        echo json_encode(['success' => false, 'error' => 'This phone number is already registered.']);
    } else {
        error_log("Registration Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
}
?>