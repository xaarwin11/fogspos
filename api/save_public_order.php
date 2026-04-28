<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0); // Prevents PHP warnings from breaking the JSON response

$mysqli = get_db_conn(); 

try {
    if (!isset($_POST['cart'])) throw new Exception("Cart is empty or failed to send.");
    $cart = json_decode($_POST['cart'], true);
    if (empty($cart)) throw new Exception("Your cart is empty.");

    $checkout_type = $_POST['checkout_type'] ?? 'guest';
    $cust_name = "Guest";
    $cust_phone = "";

    // --- AUTHENTICATION ---
    if ($checkout_type === 'account') {
        $phone = trim($_POST['account_phone'] ?? '');
        $pin = trim($_POST['account_pin'] ?? '');
        
        $stmt = $mysqli->prepare("SELECT id, name, passcode FROM verified_customers WHERE phone = ? AND is_active = 1");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($cust = $res->fetch_assoc()) {
            if (password_verify($pin, $cust['passcode']) || $pin === $cust['passcode']) {
                $cust_name = $cust['name'];
                $cust_phone = $phone;
            } else {
                throw new Exception("Invalid password for this account.");
            }
        } else {
            throw new Exception("Account not found. Please use Guest checkout or register first.");
        }
    } else {
        $cust_name = trim($_POST['customer_name'] ?? 'Guest');
        $cust_phone = trim($_POST['customer_phone'] ?? '');
    }

    $cust_info = trim($cust_name . ($cust_phone ? " ($cust_phone)" : ""));

    // 🔒 LOCK DATABASE FOR SAFE INSERTION
    $mysqli->begin_transaction();

    // --- 1. CREATE MASTER ORDER ---
    $ref = "WEB-" . strtoupper(bin2hex(random_bytes(3))); 
    $discount_note = isset($_POST['sc_type']) ? "PENDING SC/PWD VERIFICATION" : null;

    $o_stmt = $mysqli->prepare("INSERT INTO orders (reference, order_type, customer_name, status, discount_note, created_at) VALUES (?, 'takeout', ?, 'open', ?, NOW())");
    $o_stmt->bind_param("sss", $ref, $cust_info, $discount_note);
    $o_stmt->execute();
    $order_id = $mysqli->insert_id;

    // --- 2. LOG SC/PWD DISCOUNT (No image upload required) ---
    if (isset($_POST['sc_type'])) {
        $sc_stmt = $mysqli->prepare("INSERT INTO order_sc_pwd (order_id, discount_type, person_name, id_number) VALUES (?, ?, ?, ?)");
        $sc_stmt->bind_param("isss", $order_id, $_POST['sc_type'], $_POST['sc_name'], $_POST['sc_idnum']);
        $sc_stmt->execute();
    }

    // --- 3. PROCESS CART ITEMS (POS MIRROR) ---
    $raw_subtotal = 0;

    $p_stmt = $mysqli->prepare("SELECT name, price FROM products WHERE id = ?");
    $v_stmt = $mysqli->prepare("SELECT id, price FROM product_variations WHERE product_id = ? AND name = ?");
    $m_stmt = $mysqli->prepare("SELECT id, name, price FROM modifiers WHERE name = ?");
    
    $item_insert = $mysqli->prepare("INSERT INTO order_items (order_id, product_id, variation_id, product_name, variation_name, base_price, modifier_total, line_total, quantity, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $mod_insert = $mysqli->prepare("INSERT INTO order_item_modifiers (order_item_id, modifier_id, name, price) VALUES (?, ?, ?, ?)");

    foreach ($cart as $item) {
        $pid = (int)$item['id'];
        $qty = (int)$item['qty'];

        // Get actual Product Base Price & Name from DB (Never trust frontend prices)
        $p_stmt->bind_param("i", $pid);
        $p_stmt->execute();
        $p_res = $p_stmt->get_result()->fetch_assoc();
        if (!$p_res) continue; 
        
        $p_name = $p_res['name'];
        $base_p = (float)$p_res['price'];

        // Apply Variation Price Override
        $var_id = null;
        $var_name = !empty($item['variation']) ? $item['variation'] : null;
        if ($var_name) {
            $v_stmt->bind_param("is", $pid, $var_name);
            $v_stmt->execute();
            if ($v_row = $v_stmt->get_result()->fetch_assoc()) {
                $var_id = $v_row['id'];
                $base_p = (float)$v_row['price'];
            }
        }

        // Apply Modifier Prices
        $mod_total = 0;
        $resolved_mods = [];
        if (!empty($item['modifiers'])) {
            foreach ($item['modifiers'] as $m_name) {
                $m_stmt->bind_param("s", $m_name);
                $m_stmt->execute();
                if ($m_row = $m_stmt->get_result()->fetch_assoc()) {
                    $mod_total += (float)$m_row['price'];
                    $resolved_mods[] = $m_row;
                }
            }
        }

        $line_total = ($base_p + $mod_total) * $qty;
        $raw_subtotal += $line_total;

        // Insert Item
        $item_insert->bind_param("iiissdddi", $order_id, $pid, $var_id, $p_name, $var_name, $base_p, $mod_total, $line_total, $qty);
        $item_insert->execute();
        $order_item_id = $mysqli->insert_id;

        // Insert Modifiers to Junction Table
        foreach ($resolved_mods as $rm) {
            $mod_insert->bind_param("iisd", $order_item_id, $rm['id'], $rm['name'], $rm['price']);
            $mod_insert->execute();
        }
    }

    // --- 4. FINALIZE TOTALS ---
    $upd_stmt = $mysqli->prepare("UPDATE orders SET subtotal = ?, grand_total = ? WHERE id = ?");
    $upd_stmt->bind_param("ddi", $raw_subtotal, $raw_subtotal, $order_id);
    $upd_stmt->execute();

    // Log the order
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $details = json_encode(['source' => 'web_app', 'type' => 'takeout']);
    $log_stmt = $mysqli->prepare("INSERT INTO audit_log (action_type, target_type, target_id, details, ip_address, created_at) VALUES ('order_created', 'order', ?, ?, ?, NOW())");
    $log_stmt->bind_param("iss", $order_id, $details, $ip);
    $log_stmt->execute();

    $mysqli->commit();
    echo json_encode(['success' => true, 'order_id' => $ref]);

} catch (Exception $e) {
    if (isset($mysqli) && $mysqli->ping()) $mysqli->rollback(); 
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>