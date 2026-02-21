<?php
require __DIR__ . '/../library/printerService.php';
require __DIR__ . '/../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

function getPrinterConfig($mysqli, $setting_key) {
    $p_query = "SELECT p.* FROM printers p JOIN system_settings s ON p.id = s.setting_value WHERE s.setting_key = ? AND p.is_active = 1 LIMIT 1";
    $p_stmt = $mysqli->prepare($p_query); 
    $p_stmt->bind_param("s", $setting_key);
    $p_stmt->execute();
    return $p_stmt->get_result()->fetch_assoc();
}

try {
    $mysqli = get_db_conn();

    // 1. Get the most recently closed register shift
    $res = $mysqli->query("SELECT r.*, u.username as closer_name FROM register_shifts r LEFT JOIN users u ON r.closed_by = u.id WHERE r.status = 'closed' ORDER BY r.closed_at DESC LIMIT 1");
    $shift = $res->fetch_assoc();

    if (!$shift) throw new Exception("No closed shift found to print.");

    // 2. Fetch the breakdown of Sales vs Refunds for this specific shift timeframe
    $stmt = $mysqli->prepare("SELECT 
        COALESCE(SUM(CASE WHEN amount > 0 THEN amount - change_given ELSE 0 END), 0) as gross_cash,
        COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) as cash_refunds
        FROM payments WHERE method = 'cash' AND created_at >= ? AND created_at <= ?");
    $stmt->bind_param('ss', $shift['opened_at'], $shift['closed_at']);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $gross_cash = (float)$totals['gross_cash'];
    $cash_refunds = (float)$totals['cash_refunds'];

    // 3. Get Business Profile for the header
    $biz_res = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE category = 'business'");
    $biz = [];
    while ($b_row = $biz_res->fetch_assoc()) $biz[$b_row['setting_key']] = $b_row['setting_value'];

    // 4. Format the stats as "Items" so the Printer Engine understands them
    $items = [];
    $items[] = ['quantity' => 1, 'name' => 'OPENING FLOAT', 'price' => (float)$shift['opening_cash']];
    $items[] = ['quantity' => 1, 'name' => 'GROSS CASH SALES', 'price' => $gross_cash];
    
    if ($cash_refunds < 0) {
        // Pass it as a negative number so the printer formats it with a minus sign
        $items[] = ['quantity' => 1, 'name' => 'REFUNDS PAID OUT', 'price' => $cash_refunds];
    }

    $items[] = ['quantity' => 1, 'name' => 'EXPECTED IN DRAWER', 'price' => (float)$shift['expected_cash']];
    $items[] = ['quantity' => 1, 'name' => 'ACTUAL COUNTED', 'price' => (float)$shift['actual_cash']];
    $items[] = ['quantity' => 1, 'name' => 'VARIANCE (OVER/SHORT)', 'price' => (float)$shift['variance']];

    $meta = [
        'Store'   => $biz['store_name'] ?? 'FOGS RESTAURANT',
        'Address' => $biz['store_address'] ?? '',
        'Phone'   => $biz['store_phone'] ?? '',
        'Staff'   => $shift['closer_name'],
        'Date'    => date('M d, Y h:i A', strtotime($shift['closed_at'])),
        'Ref'     => 'SHIFT ID: ' . $shift['id']
    ];

    // 5. Send to the Receipt Printer
    $conf = getPrinterConfig($mysqli, 'route_receipt');
    if (!$conf) throw new Exception("Receipt printer not assigned in settings.");

    $p = new PrinterService($conf['connection_type'], $conf['path'], (int)$conf['character_limit']);
    
    $p->printTicket("END OF SHIFT (Z-REPORT)", $items, $meta, true, [
        'beep' => (int)($conf['beep_on_print'] ?? 1),
        'cut' => (int)($conf['cut_after_print'] ?? 1)
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>