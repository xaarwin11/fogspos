<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($input['order_id'] ?? 0);
$tip_amount = (float)($input['tip_amount'] ?? 0);

if (!$order_id || $tip_amount <= 0) { echo json_encode(['success' => false, 'error' => 'Invalid amount.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    $stmt = $mysqli->prepare("UPDATE orders SET tip_amount = tip_amount + ? WHERE id = ?");
    $stmt->bind_param('di', $tip_amount, $order_id);
    $stmt->execute();
    $stmt->close();

    // 🌟 NEW: Record this loose cash in the payments table so the Cash Drawer stays balanced!
    $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, 'cash', ?, 0, ?, NOW())");
    $p_stmt->bind_param('idi', $order_id, $tip_amount, $_SESSION['user_id']);
    $p_stmt->execute();
    $p_stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>