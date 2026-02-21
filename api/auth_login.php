<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Safely grab the PIN from either JSON or FormData
$input = json_decode(file_get_contents('php://input'), true);
$pin = $input['passcode'] ?? $_POST['passcode'] ?? '';
$pin = trim((string)$pin);

if (empty($pin)) {
    echo json_encode(['success' => false, 'error' => 'No passcode reached the server. Your phone might be blocking the request.']);
    exit;
}

try {
    $mysqli = get_db_conn();
    $sql = "SELECT id, username, passcode, role_id FROM users WHERE is_active = 1";
    $res = $mysqli->query($sql);
    
    if (!$res) throw new Exception("Failed to search users table: " . $mysqli->error);
    
    $found = false;
    
    while($user = $res->fetch_assoc()) {
        if(password_verify($pin, $user['passcode'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            $r_sql = "SELECT role_name FROM roles WHERE id = " . $user['role_id'];
            $_SESSION['role'] = $mysqli->query($r_sql)->fetch_assoc()['role_name'] ?? 'staff';
            
            $found = true;
            break;
        }
    }
    
    if ($found) {
        // FIX: Pass the CSRF token back so the POS can rescue a dead session!
        if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
        echo json_encode(['success' => true, 'csrf_token' => $_SESSION['csrf_token']]);
    } else {
        // DIAGNOSTIC OUTPUT: Sends back exactly what the server saw!
        echo json_encode(['success' => false, 'error' => "Server checked PIN: '$pin'. It did not match the database hashes."]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DATABASE CRASH: ' . $e->getMessage()]);
}
?>