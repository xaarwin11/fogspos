<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;
$method = $input['method'] ?? 'cash';
$amount = !empty($input['amount']) ? (float)$input['amount'] : 0;

if (!$order_id) { echo json_encode(['success' => false, 'error' => 'Order ID is required.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    $o_stmt = $mysqli->prepare("SELECT grand_total, status FROM orders WHERE id = ?");
    $o_stmt->bind_param('i', $order_id);
    $o_stmt->execute();
    $order = $o_stmt->get_result()->fetch_assoc();

    if (!$order || $order['status'] !== 'open') throw new Exception("Order is already closed or doesn't exist.");

    // Check existing payments for Split Bill
    $paid_stmt = $mysqli->query("SELECT COALESCE(SUM(amount - change_given), 0) as paid FROM payments WHERE order_id = $order_id");
    $already_paid = (float)$paid_stmt->fetch_assoc()['paid'];
    
    $balance = (float)$order['grand_total'] - $already_paid;
    if ($balance <= 0) throw new Exception("This order is already fully paid.");

    // Calculate actual change
    $change = 0;
    if ($amount > $balance) {
        $change = $amount - $balance;
    }
    
    // Net payment going into the drawer
    $net_payment = $amount - $change;

    // Insert Payment Record
    $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $p_stmt->bind_param('isddi', $order_id, $method, $amount, $change, $_SESSION['user_id']);
    $p_stmt->execute();

    // Determine if Fully Paid
    $new_total_paid = $already_paid + $net_payment;
    if ($new_total_paid >= ((float)$order['grand_total'] - 0.01)) { // 0.01 for float safety
        $u_stmt = $mysqli->prepare("UPDATE orders SET status = 'paid', checked_out_by = ?, paid_at = NOW() WHERE id = ?");
        $u_stmt->bind_param('ii', $_SESSION['user_id'], $order_id);
        $u_stmt->execute();
        $is_fully_paid = true;
    } else {
        $is_fully_paid = false; // Split bill active
    }

    $details = json_encode(['method' => $method, 'tendered' => $amount, 'net' => $net_payment, 'full_paid' => $is_fully_paid]);
    $mysqli->query("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, created_at) VALUES (".$_SESSION['user_id'].", 'order_paid', 'order', $order_id, '$details', NOW())");

    $mysqli->commit();
    echo json_encode(['success' => true, 'is_fully_paid' => $is_fully_paid, 'change' => $change]);

} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}