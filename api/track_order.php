<?php
require_once '../db.php';
header('Content-Type: application/json');

// Stop errors from breaking JSON
ini_set('display_errors', 0);

if (empty($_GET['ref'])) {
    echo json_encode(['success' => false, 'error' => 'No reference code provided.']);
    exit;
}

$ref = trim($_GET['ref']);

try {
    $mysqli = get_db_conn();
    
    // Fetch the current status and total of the active order
    $stmt = $mysqli->prepare("SELECT status, grand_total, created_at FROM orders WHERE reference = ?");
    $stmt->bind_param("s", $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($order = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'order' => $order]);
    } else {
        throw new Exception("Order not found.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>