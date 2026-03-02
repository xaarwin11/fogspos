<?php
require_once '../db.php';
header('Content-Type: application/json');

try {
    $mysqli = get_db_conn();
    
    // We cast the table_number to an integer for natural sorting (e.g., 1, 2, 10)
    $query = "SELECT * FROM view_table_status ORDER BY CAST(table_number AS UNSIGNED) ASC";
    $res = $mysqli->query($query);
    
    if (!$res) throw new Exception("Database query failed: " . $mysqli->error);

    $tables = [];
    $seen = []; // We will use this to block duplicate tables!
    
    while ($row = $res->fetch_assoc()) {
        $t_id = $row['id'];
        if (!isset($seen[$t_id])) {
            $tables[] = $row;
            $seen[$t_id] = true; // Mark as seen so we ignore duplicates
        }
    }

    echo json_encode($tables);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>