<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// 1. SILENT ERRORS: Log to your 'error.log' instead of the screen
ini_set('display_errors', 0);
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input'), true);
$pin = $input['passcode'] ?? $_POST['passcode'] ?? '';
$pin = trim((string)$pin);

if (empty($pin)) {
    echo json_encode(['success' => false, 'error' => 'Authentication failed.']);
    exit;
}

try {
    $mysqli = get_db_conn();
    
    // Fetch only what we need
    $sql = "SELECT id, username, passcode, role_id FROM users WHERE is_active = 1";
    $res = $mysqli->query($sql);
    
    if (!$res) throw new Exception("Login query failed.");
    
    $found = false;
    $auth_user = null;

    while($user = $res->fetch_assoc()) {
        if(password_verify($pin, $user['passcode'])) {
            $auth_user = $user;
            $found = true;
            break;
        }
    }
    
    if ($found) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $auth_user['id'];
        $_SESSION['username'] = $auth_user['username'];
        
        // 2. SECURE ROLE FETCH: Using a prepared statement
        $r_stmt = $mysqli->prepare("SELECT role_name FROM roles WHERE id = ?");
        $r_stmt->bind_param("i", $auth_user['role_id']);
        $r_stmt->execute();
        $role_res = $r_stmt->get_result()->fetch_assoc();
        $_SESSION['role'] = $role_res['role_name'] ?? 'staff';
        
        if (empty($_SESSION['csrf_token'])) { 
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
        }
        
        echo json_encode(['success' => true, 'csrf_token' => $_SESSION['csrf_token']]);
    } else {
        // 3. SECURE FAILURE: No more PIN diagnostic output
        echo json_encode(['success' => false, 'error' => "Invalid Passcode."]);
    }

} catch (Exception $e) {
    // 4. LOG THE ERROR: Keeps your server safe
    error_log("LOGIN CRASH: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error. Please try again.']);
}
?>