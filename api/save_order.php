<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$items = $input['items'] ?? [];
$order_type = $input['order_type'] ?? 'dine_in';
$table_id = !empty($input['table_id']) ? (int)$input['table_id'] : null;
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;
$discount_id = !empty($input['discount_id']) ? (int)$input['discount_id'] : null;
$discount_note = $input['discount_note'] ?? null;
$senior_details = $input['senior_details'] ?? []; 

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    if ($order_type === 'dine_in' && !$order_id) {
        $stmt = $mysqli->prepare("SELECT id FROM orders WHERE table_id = ? AND status = 'open' LIMIT 1");
        $stmt->bind_param('i', $table_id);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) $order_id = $row['id'];
        $stmt->close();
    }
    if (!$order_id) {
        $stmt = $mysqli->prepare("INSERT INTO orders (table_id, order_type, status, created_at) VALUES (?, ?, 'open', NOW())");
        $stmt->bind_param('is', $table_id, $order_type);
        $stmt->execute();
        $order_id = $mysqli->insert_id;
        $stmt->close();
    }

    $existing_map = [];
    $e_stmt = $mysqli->prepare("SELECT id, product_id, variation_id, quantity FROM order_items WHERE order_id = ?");
    $e_stmt->bind_param('i', $order_id);
    $e_stmt->execute();
    $e_res = $e_stmt->get_result();
    while ($row = $e_res->fetch_assoc()) {
        $oi_id = $row['id'];
        $m_res = $mysqli->query("SELECT modifier_id FROM order_item_modifiers WHERE order_item_id = $oi_id ORDER BY modifier_id ASC");
        $m_ids = []; while($m = $m_res->fetch_assoc()) $m_ids[] = $m['modifier_id'];
        $key = $row['product_id'] . '_' . ($row['variation_id'] ?? '0') . '_' . implode(',', $m_ids);
        $existing_map[$key] = $row;
    }
    $e_stmt->close();

    $ins_item = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, variation_id, product_name, variation_name, base_price, modifier_total, discount_amount, discount_note, quantity, line_total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $upd_item = $mysqli->prepare("UPDATE order_items SET quantity = ?, line_total = ?, discount_amount = ?, discount_note = ? WHERE id = ?");
    $del_item = $mysqli->prepare("DELETE FROM order_items WHERE id = ?");
    $ins_mod  = $mysqli->prepare("INSERT INTO order_item_modifiers (order_item_id, modifier_id, name, price) VALUES (?, ?, ?, ?)");
    
    $processed_ids = [];

    // PHASE 1: RECONCILE CART (Strips old auto-discounts to prevent stacking)
    foreach ($items as $item) {
        $p_id = (int)$item['id']; $v_id = !empty($item['variation_id']) ? (int)$item['variation_id'] : null; $qty = (int)$item['qty'];
        $item_disc_amt = !empty($item['discount_amount']) ? (float)$item['discount_amount'] : 0.00;
        $item_disc_note = !empty($item['discount_note']) ? $item['discount_note'] : null;
        
        if ($item_disc_note && (strpos($item_disc_note, 'SC') !== false || strpos($item_disc_note, 'PWD') !== false)) {
            $item_disc_amt = 0.00; $item_disc_note = null; 
        }

        $mod_ids = array_column($item['modifiers'] ?? [], 'id'); sort($mod_ids);
        $key = $p_id . '_' . ($v_id ?? '0') . '_' . implode(',', $mod_ids);

        if (isset($existing_map[$key])) {
            $existing_id = $existing_map[$key]['id'];
            $p_data = $mysqli->query("SELECT price FROM products WHERE id = $p_id")->fetch_assoc();
            $base_p = $v_id ? (float)($mysqli->query("SELECT price FROM product_variations WHERE id = $v_id")->fetch_assoc()['price']) : (float)$p_data['price'];
            $mod_total = 0; foreach (($item['modifiers'] ?? []) as $m) { $mod_total += (float)$m['price']; }
            $line_total = (($base_p + $mod_total) * $qty) - $item_disc_amt;

            $upd_item->bind_param('iddsi', $qty, $line_total, $item_disc_amt, $item_disc_note, $existing_id);
            $upd_item->execute();
            $processed_ids[] = $existing_id;
        } else {
            $p_data = $mysqli->query("SELECT name, price FROM products WHERE id = $p_id")->fetch_assoc();
            $base_p = (float)$p_data['price']; $p_name = $p_data['name']; $v_name = null;
            if ($v_id) {
                $v_data = $mysqli->query("SELECT name, price FROM product_variations WHERE id = $v_id")->fetch_assoc();
                $base_p = (float)$v_data['price']; $v_name = $v_data['name'];
            }
            $mod_total = 0; $resolved_mods = [];
            foreach (($item['modifiers'] ?? []) as $m) {
                $md = $mysqli->query("SELECT name, price FROM modifiers WHERE id = ".(int)$m['id'])->fetch_assoc();
                $mod_total += (float)$md['price']; $resolved_mods[] = ['id' => $m['id'], 'name' => $md['name'], 'price' => (float)$md['price']];
            }
            $line_total = (($base_p + $mod_total) * $qty) - $item_disc_amt;
            $ins_item->bind_param('iiissdddsid', $order_id, $p_id, $v_id, $p_name, $v_name, $base_p, $mod_total, $item_disc_amt, $item_disc_note, $qty, $line_total);
            $ins_item->execute();
            $new_id = $mysqli->insert_id; $processed_ids[] = $new_id;
            foreach ($resolved_mods as $rm) { $ins_mod->bind_param('iisd', $new_id, $rm['id'], $rm['name'], $rm['price']); $ins_mod->execute(); }
        }
    }

    foreach ($existing_map as $e_item) {
        if (!in_array($e_item['id'], $processed_ids)) {
            $del_item->bind_param('i', $e_item['id']); $del_item->execute();
            $mysqli->query("DELETE FROM order_item_modifiers WHERE order_item_id = " . (int)$e_item['id']);
        }
    }

    // PHASE 2: 1 FOOD + 1 DRINK SENIOR MATH
    $global_discount_amount = 0;
    
    if ($discount_id) {
        $d_data = $mysqli->query("SELECT type, value, target_type, name FROM discounts WHERE id = $discount_id")->fetch_assoc();
        if ($d_data) {
            $disc_val = (float)$d_data['value'];
            $multiplier = ($disc_val <= 1) ? $disc_val : ($disc_val / 100);
            $is_senior = (stripos(strtolower($d_data['name']), 'senior') !== false || $d_data['target_type'] === 'highest');
            
            if ($is_senior) {
                $food_items = []; $drink_items = [];
                $oi_res = $mysqli->query("SELECT o.id, p.category_id, o.base_price, o.modifier_total, o.quantity FROM order_items o JOIN products p ON o.product_id = p.id WHERE o.order_id = $order_id");
                
                while ($oi = $oi_res->fetch_assoc()) {
                    $cat_type = $mysqli->query("SELECT cat_type FROM categories WHERE id = " . $oi['category_id'])->fetch_assoc()['cat_type'] ?? 'food';
                    $unit_price = (float)$oi['base_price'] + (float)$oi['modifier_total'];
                    for ($i=0; $i < (int)$oi['quantity']; $i++) { 
                        if ($cat_type === 'drink') { $drink_items[] = ['id' => $oi['id'], 'price' => $unit_price]; } 
                        else { $food_items[] = ['id' => $oi['id'], 'price' => $unit_price]; }
                    }
                }
                
                // Sort both highest to lowest
                usort($food_items, function($a, $b) { return $b['price'] <=> $a['price']; });
                usort($drink_items, function($a, $b) { return $b['price'] <=> $a['price']; });
                
                $senior_count = count($senior_details) > 0 ? count($senior_details) : 1;
                
                // Grab Top N Food and Top N Drinks
                $applicable = array_merge(array_slice($food_items, 0, $senior_count), array_slice($drink_items, 0, $senior_count));
                
                $discount_updates = [];
                foreach($applicable as $index => $it) {
                    $amt = ($d_data['type'] === 'percent') ? $it['price'] * $multiplier : $disc_val;
                    
                    // Match to specific senior name & type
                    $s_idx = $index % $senior_count;
                    $s_type = !empty($senior_details[$s_idx]['type']) ? $senior_details[$s_idx]['type'] : 'SC';
                    $s_name = !empty($senior_details[$s_idx]['name']) ? $senior_details[$s_idx]['name'] : 'Senior';
                    
                    if(!isset($discount_updates[$it['id']])) $discount_updates[$it['id']] = ['amount' => 0, 'names' => []];
                    $discount_updates[$it['id']]['amount'] += $amt;
                    $discount_updates[$it['id']]['names'][] = "$s_type [$s_name]";
                }
                
                foreach($discount_updates as $oid => $dup) {
                    $amt = $dup['amount']; 
                    $note_str = implode(', ', $dup['names']);
                    $mysqli->query("UPDATE order_items SET discount_amount = discount_amount + $amt, discount_note = CONCAT_WS(' | ', discount_note, '$note_str'), line_total = line_total - $amt WHERE id = $oid");
                }
                $discount_note = "Applied to Specific Items";
            } else {
                $sub_res = $mysqli->query("SELECT COALESCE(SUM(line_total), 0) as total FROM order_items WHERE order_id = $order_id");
                $subtotal = (float)$sub_res->fetch_assoc()['total'];
                $global_discount_amount = ($d_data['type'] === 'percent') ? $subtotal * $multiplier : $disc_val;
            }
        }
    }
    
    // PHASE 3: FINAL MATH
    $sums = $mysqli->query("SELECT COALESCE(SUM((base_price + modifier_total) * quantity), 0) as raw_sub, COALESCE(SUM(line_total), 0) as discounted_sub FROM order_items WHERE order_id = $order_id")->fetch_assoc();
    $raw_subtotal = (float)$sums['raw_sub'];
    $discounted_subtotal = (float)$sums['discounted_sub'];
    
    $grand_total = max(0, $discounted_subtotal - $global_discount_amount);
    $total_order_discount = ($raw_subtotal - $discounted_subtotal) + $global_discount_amount;
    
    $safe_note = $discount_note ? "'" . $mysqli->real_escape_string($discount_note) . "'" : "NULL";
    $safe_disc_id = $discount_id ? (int)$discount_id : "NULL";

    $mysqli->query("UPDATE orders SET discount_id = $safe_disc_id, discount_total = $total_order_discount, discount_note = $safe_note, subtotal = $raw_subtotal, grand_total = $grand_total, updated_at = NOW() WHERE id = $order_id");

    $mysqli->commit();
    echo json_encode(['success' => true, 'order_id' => $order_id, 'total' => number_format($grand_total, 2)]);
} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}