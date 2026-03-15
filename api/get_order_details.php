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

    $sc_stmt = $mysqli->prepare("SELECT * FROM order_sc_pwd WHERE order_id = ?");
    $sc_stmt->bind_param('i', $order_id);
    $sc_stmt->execute();
    $order['sc_records'] = $sc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sc_stmt->close();

    // PULL THE RELATIONAL REFUND LOGS (With safety check)
    $refund_logs = [];
    $r_log_stmt = $mysqli->prepare("SELECT r.*, u.username as manager_name FROM refunds r LEFT JOIN users u ON r.manager_id = u.id WHERE r.order_id = ? ORDER BY r.created_at ASC");
    if ($r_log_stmt) {
        $r_log_stmt->bind_param('i', $order_id);
        $r_log_stmt->execute();
        $refund_logs = $r_log_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $r_log_stmt->close();
    }

    $i_stmt = $mysqli->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $i_stmt->bind_param('i', $order_id);
    $i_stmt->execute();
    $items_res = $i_stmt->get_result();
    $i_stmt->close();
    
    $items_map = [];
    $item_ids = [];
    while ($row = $items_res->fetch_assoc()) {
        $row['modifiers'] = []; 
        $row['refunded_qty'] = 0; 
        $items_map[$row['id']] = $row;
        $item_ids[] = (int)$row['id'];
    }
    
    if (!empty($item_ids)) {
        $id_list = implode(',', $item_ids);
        
        $m_res = $mysqli->query("SELECT order_item_id, name, price FROM order_item_modifiers WHERE order_item_id IN ($id_list)");
        while ($m = $m_res->fetch_assoc()) {
            $items_map[$m['order_item_id']]['modifiers'][] = $m;
        }

        $r_res = $mysqli->query("SELECT order_item_id, SUM(qty) as total_refunded_qty FROM refund_items WHERE order_item_id IN ($id_list) GROUP BY order_item_id");
        if ($r_res) {
            while ($r = $r_res->fetch_assoc()) {
                $items_map[$r['order_item_id']]['refunded_qty'] = (int)$r['total_refunded_qty'];
            }
        }
    }
    
    $items = array_values($items_map); 

    $p_stmt = $mysqli->prepare("SELECT method, amount, change_given, created_at FROM payments WHERE order_id = ? ORDER BY created_at ASC");
    $p_stmt->bind_param('i', $order_id);
    $p_stmt->execute();
    $payments = $p_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $p_stmt->close();

    // Fetch Relational Void History (With safety check)
    $void_logs = [];
    $v_log_stmt = $mysqli->prepare("
        SELECT v.*, u.username as manager_name, 
        (SELECT GROUP_CONCAT(CONCAT(quantity, 'x ', product_name) SEPARATOR ', ') FROM void_items WHERE void_id = v.id) as voided_summary
        FROM voids v 
        LEFT JOIN users u ON v.manager_id = u.id 
        WHERE v.order_id = ? 
        ORDER BY v.created_at ASC
    ");
    if ($v_log_stmt) {
        $v_log_stmt->bind_param('i', $order_id);
        $v_log_stmt->execute();
        $void_logs = $v_log_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $v_log_stmt->close();
    }

    // SINGLE, CLEAN JSON OUTPUT
    echo json_encode([
        'success' => true, 
        'order' => $order, 
        'items' => $items, 
        'payments' => $payments, 
        'refund_logs' => $refund_logs, 
        'void_logs' => $void_logs
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>