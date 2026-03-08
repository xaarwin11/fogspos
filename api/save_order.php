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
$items = $input['items'] ?? [];
$order_type = $input['order_type'] ?? 'dine_in';
$table_id = !empty($input['table_id']) ? (int)$input['table_id'] : null;
$order_id = !empty($input['order_id']) ? (int)$input['order_id'] : null;
$discount_id = !empty($input['discount_id']) ? (int)$input['discount_id'] : null;
$discount_note = $input['discount_note'] ?? null;
$senior_details = $input['senior_details'] ?? []; 
$custom_discount = $input['custom_discount'] ?? null;
$customer_name = !empty($input['customer_name']) ? $input['customer_name'] : null;

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    if (!$order_id) {
        $stmt = $mysqli->prepare("INSERT INTO orders (table_id, order_type, status, customer_name, created_at) VALUES (?, ?, 'open', ?, NOW())");
        $stmt->bind_param('iss', $table_id, $order_type, $customer_name);
        $stmt->execute();
        $order_id = $mysqli->insert_id;
        $stmt->close();
        
        $ref_number = date('ym') . str_pad($order_id, 3, '0', STR_PAD_LEFT);
        $r_stmt = $mysqli->prepare("UPDATE orders SET reference = ? WHERE id = ?");
        $r_stmt->bind_param('si', $ref_number, $order_id);
        $r_stmt->execute();
        $r_stmt->close();
    } else {
        $c_stmt = $mysqli->prepare("UPDATE orders SET customer_name = ? WHERE id = ?");
        $c_stmt->bind_param('si', $customer_name, $order_id);
        $c_stmt->execute();
        $c_stmt->close();
    }

    $mysqli->query("DELETE FROM order_sc_pwd WHERE order_id = $order_id");
    
    if (!empty($senior_details) && is_array($senior_details)) {
        $sc_stmt = $mysqli->prepare("INSERT INTO order_sc_pwd (order_id, discount_type, person_name, id_number, address) VALUES (?, ?, ?, ?, ?)");
        foreach ($senior_details as $sc) {
            $type = $sc['type'] ?? 'SC';
            $name = $sc['name'] ?? '';
            $id_num = $sc['id'] ?? '';
            $address = $sc['address'] ?? '';
            $sc_stmt->bind_param("issss", $order_id, $type, $name, $id_num, $address);
            $sc_stmt->execute();
        }
        $sc_stmt->close();
    }

    $existing_map = [];
    $e_stmt = $mysqli->prepare("SELECT id, product_id, variation_id, quantity FROM order_items WHERE order_id = ?");
    $e_stmt->bind_param('i', $order_id);
    $e_stmt->execute();
    $e_res = $e_stmt->get_result();

    $m_stmt = $mysqli->prepare("SELECT modifier_id FROM order_item_modifiers WHERE order_item_id = ? ORDER BY modifier_id ASC");
    
    while ($row = $e_res->fetch_assoc()) {
        $oi_id = $row['id'];
        $m_stmt->bind_param('i', $oi_id);
        $m_stmt->execute();
        $m_res = $m_stmt->get_result();
        $m_ids = []; while($m = $m_res->fetch_assoc()) $m_ids[] = $m['modifier_id'];
        
        // Include product_id in key. If it's a custom item, product_id is null.
        $key = ($row['product_id'] ?? 'custom') . '_' . ($row['variation_id'] ?? '0') . '_' . implode(',', $m_ids);
        $existing_map[$key] = $row;
    }
    $e_stmt->close();
    $m_stmt->close();

    $ins_item = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, variation_id, product_name, variation_name, base_price, modifier_total, discount_amount, discount_note, item_notes, quantity, line_total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $upd_item = $mysqli->prepare("UPDATE order_items SET quantity = ?, line_total = ?, discount_amount = ?, discount_note = ?, item_notes = ? WHERE id = ?");
    $del_item = $mysqli->prepare("DELETE FROM order_items WHERE id = ?");
    $ins_mod  = $mysqli->prepare("INSERT INTO order_item_modifiers (order_item_id, modifier_id, name, price) VALUES (?, ?, ?, ?)");
    
    $get_prod = $mysqli->prepare("SELECT name, price FROM products WHERE id = ?");
    $get_var = $mysqli->prepare("SELECT name, price FROM product_variations WHERE id = ?");
    $get_mod = $mysqli->prepare("SELECT name, price FROM modifiers WHERE id = ?");

    $processed_ids = [];

    foreach ($items as $item) {
        // ====================================================================
        // FIX 1: THE OFF-MENU INTERCEPTOR
        // ====================================================================
        $is_custom = ($item['id'] === 'custom_item');
        $p_id = $is_custom ? null : (int)$item['id']; // Leave product_id NULL in the database
        
        $v_id = !empty($item['variation_id']) ? (int)$item['variation_id'] : null; 
        $qty = (int)$item['qty'];
        $item_disc_amt = !empty($item['discount_amount']) ? (float)$item['discount_amount'] : 0.00;
        $item_disc_note = !empty($item['discount_note']) ? $item['discount_note'] : null;
        $item_note = !empty($item['item_notes']) ? $item['item_notes'] : null;

        $mod_ids = array_column($item['modifiers'] ?? [], 'id'); sort($mod_ids);
        
        // Use a safe key for custom items so they don't merge together by accident
        $key = ($is_custom ? 'custom_' . md5($item['name']) : $p_id) . '_' . ($v_id ?? '0') . '_' . implode(',', $mod_ids);

        $base_p = 0; $p_name = ''; $v_name = null;
        
        if ($is_custom) {
            // Off-Menu Item
            $base_p = (float)$item['price'];
            $p_name = $item['name'];
        } else {
            // Standard Database Item
            $get_prod->bind_param('i', $p_id); $get_prod->execute();
            if ($p_data = $get_prod->get_result()->fetch_assoc()) { 
                $base_p = (float)$p_data['price']; 
                $p_name = $p_data['name']; 
                
                // 🌟 THE VARIABLE ITEM RULE: 
                // If the owner set the DB price to 0, trust the tablet's custom price!
                if ($base_p == 0) {
                    $base_p = (float)$item['price']; 
                }
            }
        }

        if ($v_id && !$is_custom) {
            $get_var->bind_param('i', $v_id); $get_var->execute();
            if ($v_data = $get_var->get_result()->fetch_assoc()) { $base_p = (float)$v_data['price']; $v_name = $v_data['name']; }
        }

        $mod_total = 0; $resolved_mods = [];
        foreach (($item['modifiers'] ?? []) as $m) {
            $mid = (int)$m['id'];
            $get_mod->bind_param('i', $mid); $get_mod->execute();
            if ($md = $get_mod->get_result()->fetch_assoc()) {
                $mod_total += (float)$md['price']; 
                $resolved_mods[] = ['id' => $mid, 'name' => $md['name'], 'price' => (float)$md['price']];
            }
        }
        $line_total = (($base_p + $mod_total) * $qty) - $item_disc_amt;

        if (isset($existing_map[$key])) {
            $existing_id = $existing_map[$key]['id'];
            $upd_item->bind_param('iddssi', $qty, $line_total, $item_disc_amt, $item_disc_note, $item_note, $existing_id);
            $upd_item->execute();
            $processed_ids[] = $existing_id;
        } else {
            $ins_item->bind_param('iiissdddssid', $order_id, $p_id, $v_id, $p_name, $v_name, $base_p, $mod_total, $item_disc_amt, $item_disc_note, $item_note, $qty, $line_total);
            $ins_item->execute();
            $new_id = $mysqli->insert_id; $processed_ids[] = $new_id;
            foreach ($resolved_mods as $rm) { 
                $ins_mod->bind_param('iisd', $new_id, $rm['id'], $rm['name'], $rm['price']); 
                $ins_mod->execute(); 
            }
        }
    }

    foreach ($existing_map as $e_item) {
        if (!in_array($e_item['id'], $processed_ids)) {
            $del_item->bind_param('i', $e_item['id']); $del_item->execute();
            $del_mods = $mysqli->prepare("DELETE FROM order_item_modifiers WHERE order_item_id = ?");
            $del_mods->bind_param('i', $e_item['id']); $del_mods->execute(); $del_mods->close();
        }
    }

    $global_discount_amount = 0;
    $EXCLUDED_CATEGORY_ID = 7; 
    
    // ====================================================================
    // FIX 2: LEFT JOIN PREVENTS CUSTOM ITEMS FROM CRASHING THE DISCOUNT LOOP
    // ====================================================================
    $oi_stmt = $mysqli->prepare("SELECT o.id, COALESCE(p.category_id, 0) as category_id, COALESCE(c.cat_type, 'other') as cat_type, (o.base_price + o.modifier_total) as unit_price, o.quantity, o.line_total FROM order_items o LEFT JOIN products p ON o.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE o.order_id = ?");
    $oi_stmt->bind_param('i', $order_id);
    $oi_stmt->execute();
    $db_items = $oi_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $oi_stmt->close();

    if ($discount_id) {
        $d_stmt = $mysqli->prepare("SELECT type, value, target_type, name FROM discounts WHERE id = ?");
        $d_stmt->bind_param('i', $discount_id);
        $d_stmt->execute();
        $d_data = $d_stmt->get_result()->fetch_assoc();
        $d_stmt->close();

        if ($d_data) {
            $disc_val = (float)$d_data['value'];
            $multiplier = ($disc_val <= 1) ? $disc_val : ($disc_val / 100);
            $is_senior = (stripos(strtolower($d_data['name']), 'senior') !== false || $d_data['target_type'] === 'highest');
            
            $target_cats = [];
            if ($d_data['target_type'] === 'custom') { 
                $dc_stmt = $mysqli->prepare("SELECT category_id FROM discount_categories WHERE discount_id = ?");
                $dc_stmt->bind_param('i', $discount_id);
                $dc_stmt->execute();
                $dc_res = $dc_stmt->get_result();
                while($dc = $dc_res->fetch_assoc()) { $target_cats[] = (int)$dc['category_id']; }
                $dc_stmt->close();
            }
            
            if ($is_senior) {
                $food_items = []; $drink_items = [];
                foreach ($db_items as $oi) {
                    if ($oi['category_id'] == $EXCLUDED_CATEGORY_ID) continue; 
                    for ($i=0; $i < (int)$oi['quantity']; $i++) { 
                        if ($oi['cat_type'] === 'drink') { $drink_items[] = ['id' => $oi['id'], 'price' => (float)$oi['unit_price']]; } 
                        else { $food_items[] = ['id' => $oi['id'], 'price' => (float)$oi['unit_price']]; }
                    }
                }
                usort($food_items, function($a, $b) { return $b['price'] <=> $a['price']; });
                usort($drink_items, function($a, $b) { return $b['price'] <=> $a['price']; });
                
                $senior_count = count($senior_details) > 0 ? count($senior_details) : 1;
                $applicable = array_merge(array_slice($food_items, 0, $senior_count), array_slice($drink_items, 0, $senior_count));
                
                foreach($applicable as $index => $it) {
                    $amt = ($d_data['type'] === 'percent') ? $it['price'] * $multiplier : $disc_val;
                    $global_discount_amount += $amt;
                }
                $discount_note = null; 
            } else {
                $subtotal_applicable = 0;
                $target = $d_data['target_type'] ?? 'all';

                foreach ($db_items as $oi) {
                    if ($oi['category_id'] == $EXCLUDED_CATEGORY_ID) continue;
                    
                    $match = false;
                    if ($target === 'all') $match = true;
                    else if ($target === 'food' && $oi['cat_type'] === 'food') $match = true;
                    else if ($target === 'drink' && $oi['cat_type'] === 'drink') $match = true;
                    else if ($target === 'custom' && in_array((int)$oi['category_id'], $target_cats)) $match = true;

                    if ($match) {
                        $subtotal_applicable += (float)$oi['line_total'];
                    }
                }
                $global_discount_amount = ($d_data['type'] === 'percent') ? $subtotal_applicable * $multiplier : $disc_val;
                $discount_note = null; 
            }
        }
    } else if ($custom_discount && isset($custom_discount['is_active']) && $custom_discount['is_active']) {
        
        $c_type = $custom_discount['type'] ?? 'amount';
        $c_val = (float)($custom_discount['val'] ?? 0);
        $c_target = $custom_discount['target'] ?? 'all';
        $c_note = $custom_discount['note'] ?? 'Custom';
        $c_target_cats = $custom_discount['target_cats'] ?? []; 
        
        $target_subtotal = 0; 

        foreach ($db_items as $oi) {
            if ($oi['category_id'] == $EXCLUDED_CATEGORY_ID) continue;
            
            $match = false;
            if ($c_target === 'all') $match = true;
            else if ($c_target === 'food' && $oi['cat_type'] === 'food') $match = true;
            else if ($c_target === 'drink' && $oi['cat_type'] === 'drink') $match = true;
            else if ($c_target === 'custom' && in_array((int)$oi['category_id'], $c_target_cats)) $match = true;

            if ($match) {
                $target_subtotal += (float)$oi['line_total'];
            }
        }

        if ($c_type === 'percent') {
            $global_discount_amount = $target_subtotal * ($c_val / 100);
        } else {
            $global_discount_amount = min($c_val, $target_subtotal);
        }
        $discount_note = "Custom: $c_note";
    }
    
    $sum_stmt = $mysqli->prepare("SELECT COALESCE(SUM((base_price + modifier_total) * quantity), 0) as raw_sub, COALESCE(SUM(line_total), 0) as discounted_sub FROM order_items WHERE order_id = ?");
    $sum_stmt->bind_param('i', $order_id);
    $sum_stmt->execute();
    $sums = $sum_stmt->get_result()->fetch_assoc();
    $sum_stmt->close();

    $raw_subtotal = (float)$sums['raw_sub'];
    $discounted_subtotal = (float)$sums['discounted_sub'];
    
    $grand_total = max(0, $discounted_subtotal - $global_discount_amount);
    $total_order_discount = ($raw_subtotal - $discounted_subtotal) + $global_discount_amount;
    
    $fin_stmt = $mysqli->prepare("UPDATE orders SET discount_id = ?, discount_total = ?, discount_note = ?, subtotal = ?, grand_total = ?, updated_at = NOW() WHERE id = ?");
    $fin_stmt->bind_param('idsddi', $discount_id, $total_order_discount, $discount_note, $raw_subtotal, $grand_total, $order_id);
    $fin_stmt->execute();
    $fin_stmt->close();

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    
    if (empty($input['order_id'])) {
        $details = json_encode(['table_id' => $table_id, 'type' => $order_type]);
        $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'order_created', 'order', ?, ?, ?, NOW())");
        $log_stmt->bind_param('iiss', $_SESSION['user_id'], $order_id, $details, $ip);
        $log_stmt->execute();
    }

    if ($total_order_discount > 0) {
        $disc_details = json_encode(['amount' => $total_order_discount, 'note' => $discount_note]);
        $disc_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, target_type, target_id, details, ip_address, created_at) VALUES (?, 'discount_applied', 'order', ?, ?, ?, NOW())");
        $disc_stmt->bind_param('iiss', $_SESSION['user_id'], $order_id, $disc_details, $ip);
        $disc_stmt->execute();
    }

    $mysqli->commit();
    echo json_encode(['success' => true, 'order_id' => $order_id, 'total' => number_format($grand_total, 2)]);
} catch (Exception $e) {
    if (isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>