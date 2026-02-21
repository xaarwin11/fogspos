<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }
$user_id = (int)$_SESSION['user_id'];

try {
    $mysqli = get_db_conn();
    
    $stmt = $mysqli->prepare("SELECT id, clock_in FROM time_tracking WHERE user_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $active = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true, 'is_clocked_in' => $active ? true : false]);
        exit;
    }

    // POST request logic = Requires CSRF
    $headers = getallheaders();
    $csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        http_response_code(403); echo json_encode(['success' => false, 'error' => 'Security token invalid.']); exit;
    }

    if ($active) {
        $record_id = (int)$active['id'];
        
        $upd_stmt = $mysqli->prepare("UPDATE time_tracking SET clock_out = NOW(), hours_worked = ROUND(TIME_TO_SEC(TIMEDIFF(NOW(), clock_in)) / 3600, 2) WHERE id = ?");
        $upd_stmt->bind_param('i', $record_id);
        $upd_stmt->execute();
        
        $res_stmt = $mysqli->prepare("SELECT hours_worked FROM time_tracking WHERE id = ?");
        $res_stmt->bind_param('i', $record_id);
        $res_stmt->execute();
        $hours_decimal = (float)$res_stmt->get_result()->fetch_assoc()['hours_worked'];
        
        $h = floor($hours_decimal);
        $m = round(($hours_decimal - $h) * 60);
        $duration_str = "{$h}h {$m}m";

        echo json_encode(['success' => true, 'action' => 'clocked_out', 'message' => "Clocked out successfully. Shift duration: $duration_str"]);
    } else {
        $ins_stmt = $mysqli->prepare("INSERT INTO time_tracking (user_id, clock_in) VALUES (?, NOW())");
        $ins_stmt->bind_param('i', $user_id);
        $ins_stmt->execute();
        
        echo json_encode(['success' => true, 'action' => 'clocked_in', 'message' => 'Clocked in successfully. Have a great shift!']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => "System Error encountered."]);
}
?>