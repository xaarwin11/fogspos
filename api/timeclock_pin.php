<?php
require_once '../db.php';
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input'), true);
$pin = trim((string)($input['passcode'] ?? ''));
$action = $input['action'] ?? 'toggle'; // NEW: Expect an action (status or toggle)

if (empty($pin)) { echo json_encode(['success' => false, 'error' => 'No PIN provided.']); exit; }

try {
    $mysqli = get_db_conn();
    // NEW: Also select the username so we can greet them!
    $res = $mysqli->query("SELECT id, username, passcode FROM users WHERE is_active = 1");
    
    $user_id = null;
    $username = '';
    while($user = $res->fetch_assoc()) {
        if(password_verify($pin, $user['passcode'])) {
            $user_id = $user['id'];
            $username = $user['username'];
            break;
        }
    }
    
    if (!$user_id) { echo json_encode(['success' => false, 'error' => 'Invalid PIN.']); exit; }

    // Check their current shift status
    $stmt = $mysqli->prepare("SELECT id, clock_in FROM time_tracking WHERE user_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // STEP 1: Just checking the status? Stop here and return the info!
    if ($action === 'status') {
        echo json_encode([
            'success' => true, 
            'is_clocked_in' => $active ? true : false, 
            'username' => $username
        ]);
        exit;
    }

    // STEP 2: Actually clocking in or out
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