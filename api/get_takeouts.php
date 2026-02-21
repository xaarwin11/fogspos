<?php
require_once '../db.php';
header('Content-Type: application/json');

try {
    $mysqli = get_db_conn();
    // Added 'reference' to fetch the Customer Name
    $res = $mysqli->query("SELECT id, grand_total, reference, DATE_FORMAT(created_at, '%h:%i %p') as time FROM orders WHERE order_type = 'takeout' AND status = 'open' ORDER BY created_at DESC");
    echo json_encode($res->fetch_all(MYSQLI_ASSOC));
} catch (Exception $e) {
    echo json_encode([]);
}