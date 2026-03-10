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
$tip = !empty($input['tip']) ? (float)$input['tip'] : 0; // NEW: Catch the tip!
$customer_name = isset($input['customer_name']) ? $input['customer_name'] : null; 

if (!$order_id) { echo json_encode(['success' => false, 'error' => 'Order ID is required.']); exit; }

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    $o_stmt = $mysqli->prepare("SELECT grand_total, status FROM orders WHERE id = ? FOR UPDATE");
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

    // NEW MATH: Change is calculated AFTER the tip is removed from the tendered amount!
    $change = ($amount > ($balance + $tip)) ? ($amount - ($balance + $tip)) : 0;
    
    // The actual money going towards the food bill
    $net_payment = $amount - $change - $tip; 

    // Save the Tip to the Orders table
    if ($tip > 0) {
        $t_stmt = $mysqli->prepare("UPDATE orders SET tip_amount = tip_amount + ? WHERE id = ?");
        $t_stmt->bind_param('di', $tip, $order_id);
        $t_stmt->execute();
        $t_stmt->close();
    }

    // Save the Payment Ledger
    $p_stmt = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $p_stmt->bind_param('isddi', $order_id, $method, $amount, $change, $_SESSION['user_id']);
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

    // Audit Log
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $details = json_encode(['method' => $method, 'tendered' => $amount, 'tip' => $tip, 'net' => $net_payment, 'full_paid' => $is_fully_paid]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'payment', 'order', ?, ?, ?, NOW())");
    $log_stmt->bind_param('iiss', $_SESSION['user_id'], $order_id, $details, $ip_address);
    $log_stmt->execute();
    $log_stmt->close();

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