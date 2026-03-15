<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Security Fix: Enforce CSRF token protection
$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$pin = trim((string)($input['passcode'] ?? ''));
$action = $input['action'] ?? 'toggle'; 

if (empty($pin)) { echo json_encode(['success' => false, 'error' => 'No PIN provided.']); exit; }

try {
    $mysqli = get_db_conn();
    $pin_len = strlen($pin);
    
    // Performance Fix: Only scan users with the exact same PIN length!
    $stmt = $mysqli->prepare("SELECT id, username, passcode FROM users WHERE is_active = 1 AND pin_length = ?");
    $stmt->bind_param('i', $pin_len);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $user_id = null;
    $username = '';
    while($user = $res->fetch_assoc()) {
        if(password_verify($pin, $user['passcode'])) {
            $user_id = $user['id'];
            $username = $user['username'];
            break;
        }
    }
    $stmt->close();
    
    if (!$user_id) { echo json_encode(['success' => false, 'error' => 'Invalid PIN.']); exit; }

    $stmt = $mysqli->prepare("SELECT id, clock_in FROM time_tracking WHERE user_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($action === 'status') {
        echo json_encode(['success' => true, 'is_clocked_in' => $active ? true : false, 'username' => $username]);
        exit;
    }

    if ($active) {
        $record_id = (int)$active['id'];
        $upd_stmt = $mysqli->prepare("UPDATE time_tracking SET clock_out = NOW(), hours_worked = ROUND(TIME_TO_SEC(TIMEDIFF(NOW(), clock_in)) / 3600, 2) WHERE id = ?");
        $upd_stmt->bind_param('i', $record_id);
        $upd_stmt->execute();
        echo json_encode(['success' => true, 'action' => 'clocked_out', 'message' => "Goodbye $username! Clocked out successfully."]);
    } else {
        $ins_stmt = $mysqli->prepare("INSERT INTO time_tracking (user_id, clock_in) VALUES (?, NOW())");
        $ins_stmt->bind_param('i', $user_id);
        $ins_stmt->execute();
        echo json_encode(['success' => true, 'action' => 'clocked_in', 'message' => "Welcome $username! Clocked in successfully."]);
    }
} catch (Exception $e) { 
    echo json_encode(['success' => false, 'error' => 'Internal server error.']); 
}
?>