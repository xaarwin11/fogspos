<?php
require_once '../db.php';
header('Content-Type: application/json');

$t_id = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$o_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0; 

try {
    $mysqli = get_db_conn();

    if ($o_id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM orders WHERE id = ? AND status IN ('open', 'preparing', 'ready')");
        $stmt->bind_param('i', $o_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
    } else if ($t_id > 0) {
        // THE UPGRADE: Fetch open orders AND a fast summary of their items!
        $stmt = $mysqli->prepare("
            SELECT o.id, o.customer_name, o.grand_total, 
                   GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.product_name) SEPARATOR ', ') as item_summary
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE o.table_id = ? AND o.status IN ('open', 'preparing', 'ready')
            GROUP BY o.id
            ORDER BY o.id ASC
        ");
        $stmt->bind_param('i', $t_id);
        $stmt->execute();
        $open_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($open_orders) === 0) {
            echo json_encode(['success' => false, 'message' => 'No active order']); exit;
        } else if (count($open_orders) > 1) {
            // TRIGGER THE SUB-CHECK MODAL (Now includes item_summary!)
            echo json_encode(['success' => true, 'multiple' => true, 'orders' => $open_orders]); exit;
        } else {
            $single_id = $open_orders[0]['id'];
            $stmt = $mysqli->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->bind_param('i', $single_id);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No ID provided']); exit;
    }
    
    if (!$order) { echo json_encode(['success' => false, 'message' => 'No active order']); exit; }
    $order_id = (int)$order['id'];

    $paid_res = $mysqli->query("SELECT COALESCE(SUM(amount - change_given), 0) as total_paid FROM payments WHERE order_id = $order_id");
    $order['amount_paid'] = (float)$paid_res->fetch_assoc()['total_paid'];

    $sc_stmt = $mysqli->prepare("SELECT discount_type as type, person_name as name, id_number as id, address FROM order_sc_pwd WHERE order_id = ?");
    $sc_stmt->bind_param('i', $order_id);
    $sc_stmt->execute();
    $sc_res = $sc_stmt->get_result();
    $senior_details = [];
    while($sc = $sc_res->fetch_assoc()) { $senior_details[] = $sc; }
    $order['senior_details'] = $senior_details;
    $sc_stmt->close();

    $items_stmt = $mysqli->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $items_stmt->bind_param('i', $order_id);
    $items_stmt->execute();
    $items_res = $items_stmt->get_result();

    // Fetch all items at once
    $cart_items = [];
    $item_ids = [];
    while ($row = $items_res->fetch_assoc()) {
        $cart_items[] = $row;
        $item_ids[] = (int)$row['id'];
    }

    $all_modifiers = [];
    if (!empty($item_ids)) {
        // Fetch ALL modifiers for ALL items in exactly ONE query!
        $id_list = implode(',', $item_ids);
        $m_res = $mysqli->query("SELECT order_item_id, modifier_id as id, name, price FROM order_item_modifiers WHERE order_item_id IN ($id_list)");
        while ($m = $m_res->fetch_assoc()) {
            $all_modifiers[$m['order_item_id']][] = [
                'id' => (int)$m['id'],
                'name' => $m['name'],
                'price' => (float)$m['price']
            ];
        }
    }

    $cart = [];
    foreach ($cart_items as $row) {
        $oi_id = (int)$row['id'];
        $display_name = $row['variation_name'] ? $row['product_name'] . ' (' . $row['variation_name'] . ')' : $row['product_name'];
        
        $cart[] = [
            'id' => is_null($row['product_id']) ? 'custom_item' : (int)$row['product_id'], 
            'variation_id' => $row['variation_id'] ? (int)$row['variation_id'] : null,
            'name' => $display_name, 'price' => (float)$row['base_price'], 'qty' => (int)$row['quantity'],
            'discount_amount' => (float)$row['discount_amount'], 'discount_note' => $row['discount_note'], 
            'item_notes' => $row['item_notes'] ?? null,
            // Automatically grab the modifiers from the pre-loaded array!
            'modifiers' => $all_modifiers[$oi_id] ?? [] 
        ];
    }
    echo json_encode(['success' => true, 'order_id' => $order_id, 'order_info' => $order, 'items' => $cart]);
    exit;
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
?>