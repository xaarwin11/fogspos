<?php
require __DIR__ . '/../library/printerService.php';
require __DIR__ . '/../db.php';
session_start();
header('Content-Type: application/json');

function getPrinterConfig($mysqli, $setting_key) {
    $p_query = "
        SELECT p.* FROM printers p 
        JOIN system_settings s ON p.id = s.setting_value 
        WHERE s.setting_key = ? AND p.is_active = 1 
        LIMIT 1
    ";
    $p_stmt = $mysqli->prepare($p_query); 
    $p_stmt->bind_param("s", $setting_key);
    $p_stmt->execute();
    return $p_stmt->get_result()->fetch_assoc();
}

try {
    $mysqli = get_db_conn();
    $order_id = $_GET['order_id'] ?? null;
    $type     = $_GET['type'] ?? 'bill'; 

    if (!$order_id) throw new Exception("Missing order ID");

    $printer_errors = [];

    // --- FETCH MAIN ITEMS ---
    // FIX: Use LEFT JOINs so custom items (NULL product_id) are not skipped!
    // FIX: Use COALESCE to force custom items to be 'food' so they route to the kitchen printer
    $sql = "
        SELECT 
            oi.id as order_item_id, oi.quantity, oi.kitchen_printed,
            oi.product_name, oi.variation_name, p.category_id,
            COALESCE(c.cat_type, 'food') as cat_type, 
            oi.base_price, oi.modifier_total, oi.discount_amount, oi.discount_note, oi.item_notes
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE oi.order_id = ?
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($rows)) throw new Exception("No items found for this order.");

    // FETCH ALL MODIFIERS
    $mod_sql = "SELECT order_item_id, name FROM order_item_modifiers WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = ?)";
    $mod_stmt = $mysqli->prepare($mod_sql);
    $mod_stmt->bind_param("i", $order_id);
    $mod_stmt->execute();
    $all_mods = $mod_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $mods_by_item = [];
    foreach ($all_mods as $m) {
        $mods_by_item[$m['order_item_id']][] = ['name' => $m['name']];
    }

    foreach ($rows as &$r) {
        $r['modifiers'] = $mods_by_item[$r['order_item_id']] ?? [];
    }
    unset($r);

    // --- FETCH BUSINESS PROFILE & META ---
    $biz_res = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE category = 'business'");
    $biz = [];
    while ($b_row = $biz_res->fetch_assoc()) $biz[$b_row['setting_key']] = $b_row['setting_value'];

    $o_stmt = $mysqli->prepare("SELECT o.*, t.table_number FROM orders o LEFT JOIN tables t ON o.table_id = t.id WHERE o.id = ?");
    $o_stmt->bind_param("i", $order_id);
    $o_stmt->execute();
    $order_meta = $o_stmt->get_result()->fetch_assoc();

    $discount_label = "DISCOUNT";
    if (!empty($order_meta['discount_id'])) {
        $did = (int)$order_meta['discount_id'];
        $d_res = $mysqli->query("SELECT name, type, value FROM discounts WHERE id = $did");
        if ($d = $d_res->fetch_assoc()) {
            if ($d['type'] === 'percent') {
                $discount_label = strtoupper($d['name']) . " (" . floatval($d['value']) . "%)";
            } else {
                $discount_label = strtoupper($d['name']) . " DISC";
            }
        }
    } elseif (!empty($order_meta['discount_note']) && strpos($order_meta['discount_note'], 'Custom:') !== false) {
        $cleaned_note = str_replace('Custom: ', '', $order_meta['discount_note']);
        $ext = explode('|', $cleaned_note)[0];
        $discount_label = "DISC (" . strtoupper(trim($ext)) . ")";
    }

    $sc_query = "SELECT discount_type, person_name, id_number, address FROM order_sc_pwd WHERE order_id = ?";
    $sc_stmt = $mysqli->prepare($sc_query);
    $sc_stmt->bind_param("i", $order_id);
    $sc_stmt->execute();
    $sc_records = $sc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // =========================================================
    // FIX: RE-CALCULATE SENIOR ITEM SPLIT FOR THE PRINTER
    // =========================================================
    $sc_item_count = 0; $sc_item_total = 0;
    $reg_item_count = 0; $reg_item_total = 0;
    $total_qty = 0; $raw_grand = 0;

    foreach ($rows as $r) {
        $total_qty += (int)$r['quantity'];
        $raw_grand += ((float)$r['base_price'] + (float)$r['modifier_total']) * (int)$r['quantity'];
    }

    if (!empty($sc_records) || (!empty($order_meta['discount_note']) && stripos($order_meta['discount_note'], 'SC/PWD') !== false)) {
        $discount_label = "SC DISCOUNT";
        
        $food_items = []; $drink_items = [];
        foreach ($rows as $oi) {
            if ($oi['category_id'] == 7) continue; // Exclude alcohol
            for ($i=0; $i < (int)$oi['quantity']; $i++) { 
                $price = (float)$oi['base_price'] + (float)$oi['modifier_total'];
                if ($oi['cat_type'] === 'drink') { $drink_items[] = $price; } 
                else { $food_items[] = $price; }
            }
        }
        rsort($food_items); rsort($drink_items); // Sort highest price first
        
        $s_count = count($sc_records) > 0 ? count($sc_records) : 1;
        $applicable = array_merge(array_slice($food_items, 0, $s_count), array_slice($drink_items, 0, $s_count));
        
        foreach($applicable as $price) {
            $sc_item_count++;
            $sc_item_total += $price;
        }
        $reg_item_count = $total_qty - $sc_item_count;
        $reg_item_total = $raw_grand - $sc_item_total;
    }

    $meta = [
        'Store'   => $biz['store_name'] ?? "FogsTasa's Cafe",
        'Address' => $biz['store_address'] ?? '',
        'Phone'   => $biz['store_phone'] ?? '',
        // NEW: Pass the TIN and Tax Status to the Printer Service!
        'TIN'       => $biz['store_tin'] ?? '',
        'TaxStatus' => $biz['tax_status'] ?? 'NON-VAT Reg.',
        // --------------------------------------------------------
        'Type'    => strtoupper($order_meta['order_type'] ?? 'DINE_IN'),
        'Table'   => $order_meta['table_number'] ?? '',
        'Staff'   => $_SESSION['username'] ?? 'Staff',
        'Date'    => date('M d, Y h:i A'),
        'Ref'     => $order_meta['reference'],
        'Customer'=> $order_meta['customer_name'],
        'OrderDiscount' => (float)$order_meta['discount_total'],
        'OrderDiscountNote' => $order_meta['discount_note'],
        'DiscountLabel' => $discount_label,
        'SC_Records' => $sc_records,
        'SC_ItemCount' => $sc_item_count,
        'SC_ItemTotal' => $sc_item_total,
        'Reg_ItemCount' => $reg_item_count,
        'Reg_ItemTotal' => $reg_item_total
    ];

    if ($type === 'receipt') {
        $pay_res = $mysqli->query("SELECT GROUP_CONCAT(DISTINCT method SEPARATOR ', ') as methods, SUM(amount) as tendered, SUM(change_given) as chg FROM payments WHERE order_id = $order_id");
        if ($pay = $pay_res->fetch_assoc()) {
            $meta['Tendered'] = (float)$pay['tendered'];
            $meta['Change'] = (float)$pay['chg'];
            $meta['Method'] = $pay['methods'];
        }
    }

    if ($type === 'kitchen') {
        $kitchen_items = [];
        $bar_items = [];

        foreach ($rows as $r) {
            $qty_to_print = (int)$r['quantity'] - (int)$r['kitchen_printed'];
            if ($qty_to_print <= 0) continue;

            $formatted = [
                'quantity'  => $qty_to_print,
                'name'      => $r['product_name'] . ($r['variation_name'] ? " ({$r['variation_name']})" : ""),
                'modifiers' => $r['modifiers'],
                'item_notes'=> $r['item_notes'] // <--- Send to Kitchen Printer!
            ];

            if ($r['cat_type'] === 'drink') { $bar_items[] = $formatted; } 
            else { $kitchen_items[] = $formatted; }
        }

        if (!empty($kitchen_items)) {
            try {
                $conf = getPrinterConfig($mysqli, 'route_kitchen');
                if ($conf) {
                    $p = new PrinterService($conf['connection_type'], $conf['path'], (int)$conf['character_limit']);
                    $p->printTicket("KITCHEN ORDER", $kitchen_items, $meta, false, [
                        'beep' => (int)($conf['beep_on_print'] ?? 1), 
                        'cut' => (int)($conf['cut_after_print'] ?? 1)
                    ]);
                }
            } catch (Exception $e) { $printer_errors[] = "Kitchen: " . $e->getMessage(); }
        }

        if (!empty($bar_items)) {
            try {
                $conf = getPrinterConfig($mysqli, 'route_bar');
                if ($conf) {
                    $p = new PrinterService($conf['connection_type'], $conf['path'], (int)$conf['character_limit']);
                    $p->printTicket("BAR ORDER", $bar_items, $meta, false, [
                        'beep' => (int)($conf['beep_on_print'] ?? 1), 
                        'cut' => (int)($conf['cut_after_print'] ?? 1)
                    ]);
                }
            } catch (Exception $e) { $printer_errors[] = "Bar: " . $e->getMessage(); }
        }

        $mysqli->query("UPDATE order_items SET kitchen_printed = quantity WHERE order_id = $order_id");

    } else {
        try {
            $conf = getPrinterConfig($mysqli, 'route_receipt');
            if (!$conf) throw new Exception("Receipt printer not assigned.");

            $p = new PrinterService($conf['connection_type'], $conf['path'], (int)$conf['character_limit']);
            
            $bill_items = [];
            foreach ($rows as $r) {
                $bill_items[] = [
                    'quantity' => (int)$r['quantity'],
                    'name'     => $r['product_name'] . ($r['variation_name'] ? " ({$r['variation_name']})" : ""),
                    'price'    => (float)$r['base_price'] + (float)$r['modifier_total'],
                    'modifiers'=> $r['modifiers'],
                    'discount_amount' => (float)$r['discount_amount'],
                    'discount_note' => $r['discount_note'],
                    'item_notes' => $r['item_notes'] // <--- Send to Receipt Printer!
                ];
            }

            $title = ($type === 'receipt') ? "RECEIPT" : "BILL STATEMENT";
            
            $p->printTicket($title, $bill_items, $meta, true, [
                'beep' => (int)($conf['beep_on_print'] ?? 1),
                'cut' => (int)($conf['cut_after_print'] ?? 1)
            ]);
        } catch (Exception $e) {
            $printer_errors[] = "Receipt Printer: " . $e->getMessage();
        }
    }

    echo json_encode(['success' => true, 'errors' => $printer_errors]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>