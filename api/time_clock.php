<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }
$user_id = (int)$_SESSION['user_id'];

try {
    $mysqli = get_db_conn();
    
    // Check if the user is currently clocked in
    $stmt = $mysqli->query("SELECT id, clock_in FROM time_tracking WHERE user_id = $user_id AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1");
    $active = $stmt->fetch_assoc();

    // If it's just a GET request, we return the status to color the button
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true, 'is_clocked_in' => $active ? true : false]);
        exit;
    }

    // POST request = Toggle the clock
    if ($active) {
        $record_id = $active['id'];
        
        // Use exact MySQL math to prevent PHP timezone mismatch errors
        $mysqli->query("UPDATE time_tracking SET clock_out = NOW(), hours_worked = ROUND(TIME_TO_SEC(TIMEDIFF(NOW(), clock_in)) / 3600, 2) WHERE id = $record_id");
        
        // Fetch the calculated decimal hours to display nicely
        $res = $mysqli->query("SELECT hours_worked FROM time_tracking WHERE id = $record_id");
        $hours_decimal = (float)$res->fetch_assoc()['hours_worked'];
        
        // Convert 8.5 hours to "8h 30m"
        $h = floor($hours_decimal);
        $m = round(($hours_decimal - $h) * 60);
        $duration_str = "{$h}h {$m}m";

        echo json_encode(['success' => true, 'action' => 'clocked_out', 'message' => "Clocked out successfully. Shift duration: $duration_str"]);
    } else {
        $mysqli->query("INSERT INTO time_tracking (user_id, clock_in) VALUES ($user_id, NOW())");
        echo json_encode(['success' => true, 'action' => 'clocked_in', 'message' => 'Clocked in successfully. Have a great shift!']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => "System Error: " . $e->getMessage()]);
}