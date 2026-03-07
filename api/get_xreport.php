<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

// Ensure the user is logged in
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $mysqli = get_db_conn();

    // 1. Fetch the CURRENTLY OPEN shift
    $s_query = $mysqli->query("SELECT r.*, u.username as opener FROM register_shifts r LEFT JOIN users u ON r.opened_by = u.id WHERE r.closed_at IS NULL ORDER BY r.opened_at DESC LIMIT 1");
    $shift = $s_query->fetch_assoc();
    
    if (!$shift) {
        throw new Exception("There is no open register shift to read.");
    }

    // 2. Calculate Cash Sales since the shift opened
    $p_stmt = $mysqli->prepare("SELECT COALESCE(SUM(amount - change_given), 0) as gross_cash FROM payments WHERE method = 'cash' AND created_at >= ? AND amount > 0");
    $p_stmt->bind_param('s', $shift['opened_at']);
    $p_stmt->execute();
    $gross_cash = (float)$p_stmt->get_result()->fetch_assoc()['gross_cash'];

    // 3. Calculate Cash Refunds since the shift opened
    $r_stmt = $mysqli->prepare("SELECT COALESCE(SUM(amount), 0) as cash_refunds FROM payments WHERE method = 'cash' AND created_at >= ? AND amount < 0");
    $r_stmt->bind_param('s', $shift['opened_at']);
    $r_stmt->execute();
    $cash_refunds = (float)$r_stmt->get_result()->fetch_assoc()['cash_refunds'];

    $expected = (float)$shift['opening_cash'] + $gross_cash + $cash_refunds;

    echo json_encode([
        'success' => true,
        'opener' => $shift['opener'],
        'opened_at' => date('h:i A', strtotime($shift['opened_at'])),
        'opening_cash' => (float)$shift['opening_cash'],
        'gross_cash' => $gross_cash,
        'cash_refunds' => $cash_refunds,
        'expected' => $expected
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>