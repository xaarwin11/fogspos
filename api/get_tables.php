<?php
require_once '../db.php';
header('Content-Type: application/json');

try {
    $mysqli = get_db_conn();
    
    // 1. Precise Selection from your Gold View
    // We cast the table_number to an integer for natural sorting (e.g., 1, 2, 10 instead of 1, 10, 2)
    $query = "SELECT * FROM view_table_status ORDER BY CAST(table_number AS UNSIGNED) ASC";
    
    $res = $mysqli->query($query);
    
    if (!$res) {
        throw new Exception("Database query failed: " . $mysqli->error);
    }

    // 2. Fetching as Associative Array
    $tables = $res->fetch_all(MYSQLI_ASSOC);

    // 3. Clean JSON Output
    echo json_encode($tables);

} catch (Exception $e) {
    // 4. Error Handling so your POS doesn't just show a blank screen
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
// No closing PHP tag to avoid whitespace issues