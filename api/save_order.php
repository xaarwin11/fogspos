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
    // FIX #1: Added line_total to this SELECT query!
    $e_stmt = $mysqli->prepare("SELECT id, product_id, variation_id, quantity, product_name, base_price, line_total, item_notes FROM order_items WHERE order_id = ?");
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
        
        $pid_part = is_null($row['product_id']) ? 'custom_' . md5($row['product_name']) : $row['product_id'];
        $price_part = number_format((float)$row['base_price'], 2, '.', '');
        $note_part = md5($row['item_notes'] ?? '');
        
        $key = $pid_part . '_' . ($row['variation_id'] ?? '0') . '_' . implode(',', $m_ids) . '_' . $price_part . '_' . $note_part;
        $existing_map[$key] = $row;
    }
    $e_stmt->close();
    $m_stmt->close();

    $ins_item = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, variation_id, product_name, variation_name, base_price, modifier_total, discount_amount, discount_note, item_notes, quantity, line_total, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $upd_item = $mysqli->prepare("UPDATE order_items SET quantity = ?, line_total = ?, discount_amount = ?, discount_note = ?, item_notes = ? WHERE id = ?");
    $del_item = $mysqli->prepare("DELETE FROM order_items WHERE id = ?");
    $ins_mod  = $mysqli->prepare("INSERT INTO order_item_modifiers (order_item_id, modifier_id, name, price) VALUES (?, ?, ?, ?)");
    
    // ====================================================================
    // PERFORMANCE UPGRADE: Bulk fetch products, variations, and modifiers!
    // ====================================================================
    $p_ids = []; $v_ids = []; $m_ids_all = [];
    foreach ($items as $item) {
        if ($item['id'] !== 'custom_item') $p_ids[] = (int)$item['id'];
        if (!empty($item['variation_id'])) $v_ids[] = (int)$item['variation_id'];
        foreach (($item['modifiers'] ?? []) as $m) $m_ids_all[] = (int)$m['id'];
    }
    
    $products_map = [];
    if (!empty($p_ids)) {
        $list = implode(',', array_unique($p_ids));
        $res = $mysqli->query("SELECT id, name, price FROM products WHERE id IN ($list)");
        while ($r = $res->fetch_assoc()) $products_map[$r['id']] = $r;
    }
    $variations_map = [];
    if (!empty($v_ids)) {
        $list = implode(',', array_unique($v_ids));
        $res = $mysqli->query("SELECT id, name, price FROM product_variations WHERE id IN ($list)");
        while ($r = $res->fetch_assoc()) $variations_map[$r['id']] = $r;
    }
    $modifiers_map = [];
    if (!empty($m_ids_all)) {
        $list = implode(',', array_unique($m_ids_all));
        $res = $mysqli->query("SELECT id, name, price FROM modifiers WHERE id IN ($list)");
        while ($r = $res->fetch_assoc()) $modifiers_map[$r['id']] = $r;
    }

    $processed_ids = [];

    foreach ($items as $item) {
        $is_custom = ($item['id'] === 'custom_item');
        $p_id = $is_custom ? null : (int)$item['id']; 
        
        $v_id = !empty($item['variation_id']) ? (int)$item['variation_id'] : null; 
        $qty = (int)$item['qty'];
        $item_disc_amt = !empty($item['discount_amount']) ? (float)$item['discount_amount'] : 0.00;
        $item_disc_note = !empty($item['discount_note']) ? $item['discount_note'] : null;
        $item_note = !empty($item['item_notes']) ? $item['item_notes'] : null;

        $mod_ids = array_column($item['modifiers'] ?? [], 'id'); sort($mod_ids);

        // Fetch prices from Memory Maps instead of hitting the database!
        $base_p = 0; $p_name = ''; $v_name = null;
        
        if ($is_custom) {
            $base_p = (float)$item['price'];
            $p_name = $item['name'];
        } else {
            if (isset($products_map[$p_id])) {
                $base_p = (float)$products_map[$p_id]['price'];
                $p_name = $products_map[$p_id]['name'];
                if ($base_p == 0) $base_p = (float)$item['price'];
            }
        }
        if ($v_id && !$is_custom && isset($variations_map[$v_id])) {
            $base_p = (float)$variations_map[$v_id]['price']; 
            $v_name = $variations_map[$v_id]['name']; 
        }

        $pid_part = $is_custom ? 'custom_' . md5($p_name) : $p_id;
        $price_part = number_format($base_p, 2, '.', '');
        $note_part = md5($item_note ?? '');
        $key = $pid_part . '_' . ($v_id ?? '0') . '_' . implode(',', $mod_ids) . '_' . $price_part . '_' . $note_part;

        $mod_total = 0; $resolved_mods = [];
        foreach (($item['modifiers'] ?? []) as $m) {
            $mid = (int)$m['id'];
            if (isset($modifiers_map[$mid])) {
                $md = $modifiers_map[$mid];
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

    // ====================================================================
    // KITCHEN WASTE TRACKING: Relational Void Logging
    // ====================================================================
    $items_to_void = [];
    foreach ($existing_map as $key => $e_item) {
        if (!in_array($e_item['id'], $processed_ids)) {
            $items_to_void[] = $e_item;
        }
    }

    if (!empty($items_to_void)) {
        // 1. Create the Master Void Entry
        $v_reason = "Removed from active cart/Kitchen Cancel";
        $v_stmt = $mysqli->prepare("INSERT INTO voids (order_id, manager_id, reason) VALUES (?, ?, ?)");
        $v_stmt->bind_param('iis', $order_id, $_SESSION['user_id'], $v_reason);
        $v_stmt->execute();
        $void_id = $mysqli->insert_id;
        $v_stmt->close();

        // 2. Prepare statements for items and modifiers deletion
        $vi_stmt = $mysqli->prepare("INSERT INTO void_items (void_id, product_name, quantity, amount) VALUES (?, ?, ?, ?)");
        $del_mods = $mysqli->prepare("DELETE FROM order_item_modifiers WHERE order_item_id = ?");

        foreach ($items_to_void as $item) {
            // FIX #2: Explicitly cast to prevent NULL errors
            $void_qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
            $void_amt = isset($item['line_total']) ? (float)$item['line_total'] : 0.00;

            // 3. Log into void_items
            $vi_stmt->bind_param('isid', $void_id, $item['product_name'], $void_qty, $void_amt);
            $vi_stmt->execute();

            // 4. Cleanly delete from active cart
            $del_mods->bind_param('i', $item['id']);
            $del_mods->execute();
            
            $del_item->bind_param('i', $item['id']);
            $del_item->execute();
        }
        $vi_stmt->close();
        $del_mods->close();
    }

    $global_discount_amount = 0;
    $EXCLUDED_CATEGORY_ID = 7; 
    
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
    }

    if ($custom_discount && isset($custom_discount['is_active']) && $custom_discount['is_active']) {
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

        $custom_amt = 0;
        if ($c_type === 'percent') {
            $custom_amt = $target_subtotal * ($c_val / 100);
        } else {
            $custom_amt = min($c_val, $target_subtotal);
        }

        $global_discount_amount += $custom_amt;
        
        $c_label = (strpos($c_note, 'Custom:') === false) ? "Custom: " . $c_note : $c_note;
        if ($discount_note) {
            $discount_note .= " + " . $c_label; 
        } else {
            $discount_note = $c_label;
        }
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