<?php
require_once '../db.php';
header('Content-Type: application/json');

$t_id = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
$o_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0; 

try {
    $mysqli = get_db_conn();

    if ($o_id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM orders WHERE id = ? AND status = 'open'");
        $stmt->bind_param('i', $o_id);
    } else if ($t_id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM orders WHERE table_id = ? AND status = 'open' LIMIT 1");
        $stmt->bind_param('i', $t_id);
    } else {
        echo json_encode(['success' => false, 'error' => 'No ID provided']); exit;
    }
    
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) { echo json_encode(['success' => false, 'message' => 'No active order']); exit; }
    $order_id = (int)$order['id'];

    // FETCH PAYMENTS FOR SPLIT BILL LOGIC
    $paid_res = $mysqli->query("SELECT COALESCE(SUM(amount - change_given), 0) as total_paid FROM payments WHERE order_id = $order_id");
    $order['amount_paid'] = (float)$paid_res->fetch_assoc()['total_paid'];

    // ==============================================================================
    // FIX #3: FETCH SENIOR DETAILS SO THE POS TABLET DOESN'T FORGET THEM ON RELOAD!
    // ==============================================================================
    $sc_stmt = $mysqli->prepare("SELECT discount_type as type, person_name as name, id_number as id, address FROM order_sc_pwd WHERE order_id = ?");
    $sc_stmt->bind_param('i', $order_id);
    $sc_stmt->execute();
    $sc_res = $sc_stmt->get_result();
    $senior_details = [];
    while($sc = $sc_res->fetch_assoc()) {
        $senior_details[] = $sc;
    }
    $order['senior_details'] = $senior_details;
    $sc_stmt->close();

    $items_stmt = $mysqli->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $items_stmt->bind_param('i', $order_id);
    $items_stmt->execute();
    $items_res = $items_stmt->get_result();

    $cart = [];
    while ($row = $items_res->fetch_assoc()) {
        $oi_id = (int)$row['id'];
        $m_res = $mysqli->query("SELECT modifier_id as id, name, price FROM order_item_modifiers WHERE order_item_id = $oi_id");
        $modifiers = [];
        while($m = $m_res->fetch_assoc()) {
            $m['id'] = (int)$m['id']; $m['price'] = (float)$m['price'];
            $modifiers[] = $m;
        }

        $display_name = $row['variation_name'] ? $row['product_name'] . ' (' . $row['variation_name'] . ')' : $row['product_name'];
        $cart[] = [
            'id' => (int)$row['product_id'], 'variation_id' => $row['variation_id'] ? (int)$row['variation_id'] : null,
            'name' => $display_name, 'price' => (float)$row['base_price'], 'qty' => (int)$row['quantity'],
            'discount_amount' => (float)$row['discount_amount'], 'discount_note' => $row['discount_note'], 'modifiers' => $modifiers
        ];
    }
    echo json_encode(['success' => true, 'order_id' => $order_id, 'order_info' => $order, 'items' => $cart]);
    exit;
} catch (Exception $e) { http_response_code(500); echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
?>