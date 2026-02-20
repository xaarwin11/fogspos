<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false]); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;

if ($order_id) {
    try {
        $mysqli = get_db_conn();
        // Thanks to ON DELETE CASCADE in your schema, deleting the order 
        // instantly deletes all items and modifiers attached to it!
        $stmt = $mysqli->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => true]);
}