<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$mysqli = get_db_conn();
$method = $_SERVER['REQUEST_METHOD'];

// Security: CSRF Protection for POST actions
if ($method === 'POST') {
    $headers = getallheaders();
    $csrf = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        http_response_code(403); echo json_encode(['success' => false, 'error' => 'Security token invalid.']); exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    // 1. GET STATUS & CALCULATE EXPECTED CASH
    if ($action === 'status') {
        $res = $mysqli->query("SELECT r.*, u.username as opener_name FROM register_shifts r JOIN users u ON r.opened_by = u.id WHERE r.status = 'open' LIMIT 1");
        $active = $res->fetch_assoc();

        if (!$active) {
            echo json_encode(['success' => true, 'is_open' => false]); exit;
        }

        // SPLIT MATH: Positive payments vs Negative Refunds
        $stmt = $mysqli->prepare("SELECT 
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount - change_given ELSE 0 END), 0) as gross_cash,
            COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) as cash_refunds
            FROM payments WHERE method = 'cash' AND created_at >= ?");
        $stmt->bind_param('s', $active['opened_at']);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $gross_cash = (float)$totals['gross_cash'];
        $cash_refunds = (float)$totals['cash_refunds']; // This is a negative number
        $expected = (float)$active['opening_cash'] + $gross_cash + $cash_refunds;

        echo json_encode([
            'success' => true, 'is_open' => true, 
            'opener' => $active['opener_name'], 
            'opened_at' => date('h:i A', strtotime($active['opened_at'])),
            'opening_cash' => (float)$active['opening_cash'], 
            'gross_cash' => $gross_cash,
            'cash_refunds' => $cash_refunds,
            'expected_cash' => $expected
        ]);
        exit;
    }

    // 2. OPEN DRAWER (Morning)
    if ($action === 'open') {
        $amount = (float)($input['amount'] ?? 0);
        $check = $mysqli->query("SELECT id FROM register_shifts WHERE status = 'open' LIMIT 1");
        if ($check->num_rows > 0) throw new Exception('Register is already open!');

        $stmt = $mysqli->prepare("INSERT INTO register_shifts (opened_at, opened_by, opening_cash) VALUES (NOW(), ?, ?)");
        $stmt->bind_param('id', $_SESSION['user_id'], $amount);
        $stmt->execute();
        echo json_encode(['success' => true]); exit;
    }

    // 3. CLOSE DRAWER & CALCULATE VARIANCE (Night)
    if ($action === 'close') {
        $counted = (float)($input['counted'] ?? 0);

        $res = $mysqli->query("SELECT id, opened_at, opening_cash FROM register_shifts WHERE status = 'open' LIMIT 1");
        $active = $res->fetch_assoc();
        if (!$active) throw new Exception('No open register found to close.');

        // Exact same split math for closing
        $stmt = $mysqli->prepare("SELECT 
            COALESCE(SUM(CASE WHEN amount > 0 THEN amount - change_given ELSE 0 END), 0) as gross_cash,
            COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) as cash_refunds
            FROM payments WHERE method = 'cash' AND created_at >= ?");
        $stmt->bind_param('s', $active['opened_at']);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_assoc();
        
        $gross_cash = (float)$totals['gross_cash'];
        $cash_refunds = (float)$totals['cash_refunds'];
        
        $expected = (float)$active['opening_cash'] + $gross_cash + $cash_refunds;
        $variance = $counted - $expected; // Negative = Short, Positive = Over
        $r_id = $active['id'];

        $u_stmt = $mysqli->prepare("UPDATE register_shifts SET closed_at = NOW(), closed_by = ?, expected_cash = ?, actual_cash = ?, variance = ?, status = 'closed' WHERE id = ?");
        $u_stmt->bind_param('idddi', $_SESSION['user_id'], $expected, $counted, $variance, $r_id);
        $u_stmt->execute();

        echo json_encode(['success' => true, 'variance' => $variance, 'expected' => $expected]); exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>