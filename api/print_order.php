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

    $sql = "
        SELECT 
            oi.id as order_item_id, oi.quantity, oi.kitchen_printed,
            p.name as product_name, pv.name as variation_name,
            c.cat_type, oi.base_price, oi.modifier_total, oi.discount_amount, oi.discount_note
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN product_variations pv ON oi.variation_id = pv.id
        WHERE oi.order_id = ?
    ";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($rows)) throw new Exception("No items found for this order.");

    $biz_res = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE category = 'business'");
    $biz = [];
    while ($b_row = $biz_res->fetch_assoc()) $biz[$b_row['setting_key']] = $b_row['setting_value'];

    $o_stmt = $mysqli->prepare("SELECT o.*, t.table_number FROM orders o LEFT JOIN tables t ON o.table_id = t.id WHERE o.id = ?");
    $o_stmt->bind_param("i", $order_id);
    $o_stmt->execute();
    $order_meta = $o_stmt->get_result()->fetch_assoc();

    // FIX 2: Fetch specific discount name and percentage formatting
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

    $meta = [
        'Store'   => $biz['store_name'] ?? 'FOGS RESTAURANT',
        'Address' => $biz['store_address'] ?? '',
        'Phone'   => $biz['store_phone'] ?? '',
        'Type'    => strtoupper($order_meta['order_type'] ?? 'DINE_IN'),
        'Table'   => $order_meta['table_number'] ?? '',
        'Staff'   => $_SESSION['username'] ?? 'Staff',
        'Date'    => date('M d, Y h:i A'),
        'Ref'     => $order_meta['reference'],
        'Customer'=> $order_meta['customer_name'],
        'OrderDiscount' => $order_meta['discount_total'],
        'OrderDiscountNote' => $order_meta['discount_note'],
        'DiscountLabel' => $discount_label // Passing the newly generated label
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

            $m_stmt = $mysqli->prepare("SELECT name FROM order_item_modifiers WHERE order_item_id = ?");
            $m_stmt->bind_param("i", $r['order_item_id']);
            $m_stmt->execute();
            $mods = $m_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            $formatted = [
                'quantity'  => $qty_to_print,
                'name'      => $r['product_name'] . ($r['variation_name'] ? " ({$r['variation_name']})" : ""),
                'modifiers' => $mods
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
                    'price'    => (float)$r['base_price'] + (float)$r['modifier_total']
                ];
            }

            $title = ($type === 'receipt') ? "OFFICIAL RECEIPT" : "BILL STATEMENT";
            
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