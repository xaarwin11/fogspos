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
    
    // ============================================================
    // SECURITY BLOCK 1: CHECK RATE LIMIT (Protects CPU)
    // ============================================================
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $max_attempts = 5;
    $lockout_minutes = 3;

    $limit_stmt = $mysqli->prepare("SELECT attempts, last_attempt FROM login_attempts WHERE ip_address = ?");
    $limit_stmt->bind_param("s", $ip_address);
    $limit_stmt->execute();
    $limit_res = $limit_stmt->get_result();

    if ($row = $limit_res->fetch_assoc()) {
        $attempts = (int)$row['attempts'];
        $last_attempt = strtotime($row['last_attempt']);
        $minutes_since_last = (time() - $last_attempt) / 60;

        // If locked out, reject BEFORE running the expensive password_verify loop
        if ($attempts >= $max_attempts && $minutes_since_last < $lockout_minutes) {
            $remaining = ceil($lockout_minutes - $minutes_since_last);
            echo json_encode(['success' => false, 'error' => "Too many attempts. Locked out for $remaining minute(s)."]);
            exit;
        }

        // If lockout expired, reset attempts
        if ($minutes_since_last >= $lockout_minutes) {
            $reset_stmt = $mysqli->prepare("UPDATE login_attempts SET attempts = 0 WHERE ip_address = ?");
            $reset_stmt->bind_param("s", $ip_address);
            $reset_stmt->execute();
            $reset_stmt->close();
        }
    }
    $limit_stmt->close();
    // ============================================================

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
    
    // ============================================================
    // SECURITY BLOCK 2: RECORD LOGIN RESULT
    // ============================================================
    if ($found) {
        // SUCCESS: Wipe their bad record so they have a clean slate
        $del_stmt = $mysqli->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $del_stmt->bind_param("s", $ip_address);
        $del_stmt->execute();
        $del_stmt->close();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $auth_user['id'];
        $_SESSION['username'] = $auth_user['username'];
        
        // SECURE ROLE FETCH: Using a prepared statement
        $r_stmt = $mysqli->prepare("SELECT role_name FROM roles WHERE id = ?");
        $r_stmt->bind_param("i", $auth_user['role_id']);
        $r_stmt->execute();
        $role_res = $r_stmt->get_result()->fetch_assoc();
        $_SESSION['role'] = $role_res['role_name'] ?? 'staff';
        $r_stmt->close();
        
        if (empty($_SESSION['csrf_token'])) { 
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
        }
        
        echo json_encode(['success' => true, 'csrf_token' => $_SESSION['csrf_token']]);
    } else {
        // FAILURE: Add a strike to their IP address
        $fail_stmt = $mysqli->prepare("INSERT INTO login_attempts (ip_address, attempts, last_attempt) VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()");
        $fail_stmt->bind_param("s", $ip_address);
        $fail_stmt->execute();
        $fail_stmt->close();

        // SECURE FAILURE: No more PIN diagnostic output
        echo json_encode(['success' => false, 'error' => "Invalid Passcode."]);
    }

} catch (Exception $e) {
    // LOG THE ERROR: Keeps your server safe
    error_log("LOGIN CRASH: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error. Please try again.']);
}
?>