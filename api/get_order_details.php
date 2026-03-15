<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { 
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); 
    exit; 
}

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$order_id) { 
    echo json_encode(['success' => false, 'error' => 'No Order ID provided']); 
    exit; 
}

try {
    $mysqli = get_db_conn();
    
    // Relational JOIN to grab the discount name and type
    $o_stmt = $mysqli->prepare("
        SELECT o.*, t.table_number, u.username as cashier, d.name as discount_name, d.type as discount_type, d.value as discount_value 
        FROM orders o 
        LEFT JOIN tables t ON o.table_id = t.id 
        LEFT JOIN users u ON o.checked_out_by = u.id 
        LEFT JOIN discounts d ON o.discount_id = d.id
        WHERE o.id = ?
    ");
    $o_stmt->bind_param('i', $order_id);
    $o_stmt->execute();
    $order = $o_stmt->get_result()->fetch_assoc();
    $o_stmt->close();
    
    if (!$order) throw new Exception("Order not found.");

    // Fetch SC details if they exist
    $sc_stmt = $mysqli->prepare("SELECT * FROM order_sc_pwd WHERE order_id = ?");
    $sc_stmt->bind_param('i', $order_id);
    $sc_stmt->execute();
    $order['sc_records'] = $sc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sc_stmt->close();

    $i_stmt = $mysqli->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $i_stmt->bind_param('i', $order_id);
    $i_stmt->execute();
    $items_res = $i_stmt->get_result();
    $i_stmt->close();
    
    // PERFORMANCE FIX: Fetch all modifiers in ONE query instead of N+1
    $items_map = [];
    $item_ids = [];
    while ($row = $items_res->fetch_assoc()) {
        $row['modifiers'] = []; // Initialize empty array
        $items_map[$row['id']] = $row;
        $item_ids[] = (int)$row['id'];
    }
    
    if (!empty($item_ids)) {
        $id_list = implode(',', $item_ids);
        $m_res = $mysqli->query("SELECT order_item_id, name, price FROM order_item_modifiers WHERE order_item_id IN ($id_list)");
        while ($m = $m_res->fetch_assoc()) {
            $items_map[$m['order_item_id']]['modifiers'][] = $m;
        }
    }
    
    $items = array_values($items_map); // Re-index for the JSON response

    $p_stmt = $mysqli->prepare("SELECT method, amount, change_given, created_at FROM payments WHERE order_id = ? ORDER BY created_at ASC");
    $p_stmt->bind_param('i', $order_id);
    $p_stmt->execute();
    $payments = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $p_stmt->close();

    echo json_encode(['success' => true, 'order' => $order, 'items' => $items, 'payments' => $payments]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>