<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// 1. Security Check
if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? (int)$input['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Product ID missing']);
    exit;
}

try {
    $mysqli = get_db_conn();
    
    // 2. Check for Sales History (Gold Schema Protection)
    // Table 12 (order_items) tracks product_id. If we delete a product that was sold,
    // the old orders lose their reference. 
    $check_stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM order_items WHERE product_id = ?");
    $check_stmt->bind_param('i', $id);
    $check_stmt->execute();
    $sold_count = $check_stmt->get_result()->fetch_assoc()['count'];

    if ($sold_count > 0) {
        // 3. Option A: Soft Delete (Safer for Business)
        // Set available = 0 so it disappears from the POS, but stays in reports.
        $stmt = $mysqli->prepare("UPDATE products SET available = 0, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        
        // Log the deactivation in Table 18 (Audit Log)
        $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details) VALUES (?, 'product_updated', 'product', ?, ?)");
        $details = json_encode(['status' => 'deactivated', 'reason' => 'has_sales_history']);
        $log_stmt->bind_param('iis', $_SESSION['user_id'], $id, $details);
        $log_stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Product deactivated to preserve sales records.']);
    } else {
        // 4. Option B: Hard Delete (Cleanup)
        // Product has never been sold, so it is safe to remove completely.
        $stmt = $mysqli->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Product removed permanently.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
// No closing tag to avoid JSON whitespace corruption