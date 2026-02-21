<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; 
}

$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403); echo json_encode(['success' => false, 'error' => 'Security token invalid.']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$order_id = (int)($input['order_id'] ?? 0);
$pin = (string)($input['pin'] ?? '');
$reason = $input['reason'] ?? 'Customer Request';

if (!$order_id || !$pin) { echo json_encode(['success' => false, 'error' => 'Missing required data.']); exit; }

try {
    $mysqli = get_db_conn();

    // 1. Verify Manager/Admin PIN
    $auth_stmt = $mysqli->prepare("SELECT u.id, u.passcode, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.is_active = 1");
    $auth_stmt->execute();
    $users = $auth_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $auth_stmt->close();

    $manager_id = null;
    foreach ($users as $u) {
        if (password_verify($pin, $u['passcode'])) {
            if (in_array($u['role_name'], ['admin', 'manager'])) {
                $manager_id = $u['id'];
                break;
            }
        }
    }
    if (!$manager_id) throw new Exception("Invalid PIN or insufficient permissions.");

    $mysqli->begin_transaction();

    // 2. Fetch the original payments to reverse them
    $pay_stmt = $mysqli->prepare("SELECT method, SUM(amount - change_given) as total_paid FROM payments WHERE order_id = ? GROUP BY method");
    $pay_stmt->bind_param('i', $order_id);
    $pay_stmt->execute();
    $payments = $pay_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pay_stmt->close();

    if (empty($payments)) throw new Exception("No payments found to refund.");

    // 3. Inject NEGATIVE payments into the ledger to automatically balance the Z-Report
    $ins_pay = $mysqli->prepare("INSERT INTO payments (order_id, method, amount, change_given, processed_by, created_at) VALUES (?, ?, ?, 0, ?, NOW())");
    foreach ($payments as $p) {
        $refund_amount = -abs((float)$p['total_paid']); // Force negative
        $ins_pay->bind_param('isdi', $order_id, $p['method'], $refund_amount, $manager_id);
        $ins_pay->execute();
    }
    $ins_pay->close();

    // 4. Update the order status
    $upd_stmt = $mysqli->prepare("UPDATE orders SET status = 'refunded', updated_at = NOW() WHERE id = ?");
    $upd_stmt->bind_param('i', $order_id);
    $upd_stmt->execute();
    $upd_stmt->close();

    // 5. Log the Audit Event
    $details = json_encode(['reason' => $reason, 'manager_id' => $manager_id]);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details) VALUES (?, 'refund', 'order', ?, ?)");
    $log_stmt->bind_param('iis', $manager_id, $order_id, $details);
    $log_stmt->execute();
    $log_stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>