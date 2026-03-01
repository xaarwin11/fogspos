<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid. Please refresh the page.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;
$method = $input['method'] ?? 'cash';
$amount = !empty($input['amount']) ? (float)$input['amount'] : 0;
$customer_name = isset($input['customer_name']) ? $input['customer_name'] : null; 

if (!$order_id) { echo json_encode(['success' => false, 'error' => 'Order ID is required.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    $o_stmt = $mysqli->prepare("SELECT grand_total, status FROM orders WHERE id = ?");
    $o_stmt->bind_param('i', $order_id);
    $o_stmt->execute();
    $order = $o_stmt->get_result()->fetch_assoc();
    $o_stmt->close();

    if (!$order || $order['status'] !== 'open') throw new Exception("Order is already closed or doesn't exist.");

    if ($customer_name !== null) {
        $r_stmt = $mysqli->prepare("UPDATE orders SET customer_name = ? WHERE id = ?");
        $r_stmt->bind_param('si', $customer_name, $order_id);
        $r_stmt->execute();
        $r_stmt->close();
    }

    $paid_stmt = $mysqli->prepare("SELECT COALESCE(SUM(amount - change_given), 0) as paid FROM payments WHERE order_id = ?");
    $paid_stmt->bind_param('i', $order_id);
    $paid_stmt->execute();
    $already_paid = (float)$paid_stmt->get_result()->fetch_assoc()['paid'];
    $paid_stmt->close();

    $balance = (float)$order['grand_total'] - $already_paid;
    if ($balance <= 0) throw new Exception("Order is already fully paid.");
    if ($amount <= 0) throw new Exception("Payment amount must be greater than zero.");

    $change = ($amount > $balance) ? ($amount - $balance) : 0;
    $net_payment = $amount - $change;

    // FIX: Simplified Insert (Removed processed_by and audit_log to prevent crashes)
    $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, created_at) VALUES (?, ?, ?, ?, NOW())");
    $p_stmt->bind_param('isdd', $order_id, $method, $amount, $change);
    $p_stmt->execute();
    $p_stmt->close();

    $new_total_paid = $already_paid + $net_payment;
    if ($new_total_paid >= ((float)$order['grand_total'] - 0.01)) { 
        $u_stmt = $mysqli->prepare("UPDATE orders SET status = 'paid', checked_out_by = ?, paid_at = NOW() WHERE id = ?");
        $u_stmt->bind_param('ii', $_SESSION['user_id'], $order_id);
        $u_stmt->execute();
        $u_stmt->close();
        $is_fully_paid = true;
    } else {
        $is_fully_paid = false;
    }

    $mysqli->commit();

    echo json_encode([
        'success' => true,
        'is_fully_paid' => $is_fully_paid,
        'change' => $change,
        'balance_remaining' => max(0, $balance - $net_payment)
    ]);

} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>