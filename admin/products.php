<?php
require_once '../db.php';
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

// STRICT ACCESS: Only Admins and Managers can view financials
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    header("Location: ../pos/index.php"); 
    exit; 
}

$mysqli = get_db_conn();

// Default to the current month if no dates are selected
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');

// 1. FETCH SALES TOTALS
$sales = ['total_orders' => 0, 'raw_sales' => 0, 'discounts' => 0, 'net_sales' => 0];
if ($stmt = $mysqli->prepare("SELECT COUNT(id) as total_orders, COALESCE(SUM(subtotal), 0) as raw_sales, COALESCE(SUM(discount_total), 0) as discounts, COALESCE(SUM(grand_total), 0) as net_sales FROM orders WHERE DATE(paid_at) >= ? AND DATE(paid_at) <= ? AND status = 'paid'")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $sales = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// 2. FETCH TENDER BREAKDOWN
$payments = [];
if ($stmt = $mysqli->prepare("SELECT method, SUM(amount - change_given) as net_amount FROM payments WHERE DATE(created_at) >= ? AND DATE(created_at) <= ? GROUP BY method")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 3. FETCH TOP 10 ITEMS (For the quick widget)
$top_items = [];
if ($stmt = $mysqli->prepare("SELECT product_name, SUM(quantity) as qty_sold, SUM(line_total) as total_revenue FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE DATE(o.paid_at) >= ? AND DATE(o.paid_at) <= ? AND o.status = 'paid' GROUP BY product_name ORDER BY qty_sold DESC LIMIT 10")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $top_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 3.5 FETCH ALL ITEMS (For the specific single-item lookup table)
$all_items = [];
if ($stmt = $mysqli->prepare("SELECT product_name, SUM(quantity) as qty_sold, SUM(line_total) as total_revenue FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE DATE(o.paid_at) >= ? AND DATE(o.paid_at) <= ? AND o.status = 'paid' GROUP BY product_name ORDER BY product_name ASC")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $all_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 4. FETCH KITCHEN WASTE / VOIDS
$void_logs = [];
$total_waste = 0;
if ($stmt = $mysqli->prepare("SELECT v.created_at, v.reason, vi.product_name, SUM(vi.quantity) as qty, SUM(vi.amount) as loss, u.username as manager FROM void_items vi JOIN voids v ON vi.void_id = v.id LEFT JOIN users u ON v.manager_id = u.id WHERE DATE(v.created_at) >= ? AND DATE(v.created_at) <= ? GROUP BY v.id, vi.product_name ORDER BY v.created_at DESC")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $void_res = $stmt->get_result();
    while($row = $void_res->fetch_assoc()){
        $void_logs[] = $row;
        $total_waste += (float)$row['loss'];
    }
    $stmt->close();
}

// 5. FETCH CATEGORY BREAKDOWN
$cat_sales = [];
if ($stmt = $mysqli->prepare("SELECT COALESCE(c.cat_type, 'food') as cat_type, SUM(oi.line_total) as revenue FROM order_items oi JOIN orders o ON oi.order_id = o.id LEFT JOIN products p ON oi.product_id = p.id LEFT JOIN categories c ON p.category_id = c.id WHERE DATE(o.paid_at) >= ? AND DATE(o.paid_at) <= ? AND o.status = 'paid' GROUP BY cat_type")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $cat_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 6. FETCH CASH DRAWER / REGISTER SHIFTS
$shifts = [];
if ($stmt = $mysqli->prepare("SELECT r.*, u1.username as opener, u2.username as closer FROM register_shifts r LEFT JOIN users u1 ON r.opened_by = u1.id LEFT JOIN users u2 ON r.closed_by = u2.id WHERE DATE(r.opened_at) >= ? AND DATE(r.opened_at) <= ? ORDER BY r.opened_at DESC")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 7. FETCH PAYROLL RULES & TIMESHEETS
$rules = ['reg_hours' => 9, 'ot_multi' => 1.0];
$rule_res = $mysqli->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('payroll_reg_hours', 'payroll_ot_multiplier')");
while ($r = $rule_res->fetch_assoc()) {
    if ($r['setting_key'] === 'payroll_reg_hours') $rules['reg_hours'] = (float)$r['setting_value'];
    if ($r['setting_key'] === 'payroll_ot_multiplier') $rules['ot_multi'] = (float)$r['setting_value'];
}

$timesheets = [];
if ($stmt = $mysqli->prepare("SELECT t.*, u.username, u.first_name, u.last_name, COALESCE(u.hourly_rate, 0) as hourly_rate FROM time_tracking t JOIN users u ON t.user_id = u.id WHERE DATE(t.clock_in) >= ? AND DATE(t.clock_in) <= ? AND t.clock_out IS NOT NULL ORDER BY u.first_name ASC, t.clock_in ASC")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $timesheets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Process Payroll Array
$staff_pay = [];
foreach ($timesheets as $t) {
    $uid = $t['user_id'];
    $name = ($t['first_name'] ? $t['first_name'] . ' ' . $t['last_name'] : $t['username']);
    $hrs = (float)$t['hours_worked'];
    $rate = (float)$t['hourly_rate'];
    
    if ($hrs <= 0) continue; 
    
    if (!isset($staff_pay[$uid])) {
        $staff_pay[$uid] = ['name' => $name, 'rate' => $rate, 'shifts' => 0, 'reg_hrs' => 0, 'ot_hrs' => 0];
    }
    
    $staff_pay[$uid]['shifts'] += 1;
    if ($hrs > $rules['reg_hours']) {
        $staff_pay[$uid]['reg_hrs'] += $rules['reg_hours'];
        $staff_pay[$uid]['ot_hrs'] += ($hrs - $rules['reg_hours']);
    } else {
        $staff_pay[$uid]['reg_hrs'] += $hrs;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#6B4226">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fogs POS">
    <link rel="apple-touch-icon" href="../assets/img/favicon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Analytics & Payroll - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <style>
        .report-layout { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .filter-bar { background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 30px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; justify-content: space-between;}
        .filter-group { display: flex; gap: 15px; flex-wrap: wrap; }
        .filter-item label { display: block; font-weight: bold; margin-bottom: 5px; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; }
        .filter-item input { padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; width: 180px; }
        
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: white; padding: 25px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border-top: 4px solid var(--brand); }
        .summary-card.danger { border-top-color: var(--danger); }
        .summary-card h3 { margin: 0 0 10px 0; color: var(--text-muted); font-size: 0.9rem; text-transform: uppercase; }
        .summary-card .val { font-size: 2.2rem; font-weight: 900; color: var(--text-main); margin: 0; letter-spacing:-1px;}
        
        .bento-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        @media (max-width: 900px) { .bento-grid { grid-template-columns: 1fr; } }
        
        .report-section { background: white; padding: 25px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); height: 100%; box-sizing: border-box;}
        .report-section.full-width { grid-column: 1 / -1; height: auto; margin-bottom: 30px; }
        .report-section h2 { margin-top: 0; color: var(--brand-dark); border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; font-size: 1.25rem; display: flex; justify-content: space-between; align-items: center;}
        
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .data-table th { background: #f9fafb; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; font-weight: 800;}
        .data-table tr:hover { background: #fdfaf6; }

        .adj-input { width: 80px; padding: 6px; border: 1px solid #ccc; border-radius: 6px; text-align: right; font-weight: bold; font-family: monospace; font-size: 1rem; }
        .adj-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 5px rgba(107, 66, 38, 0.3); }

        .print-only, .print-only-block { display: none !important; }
        .search-input { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; outline: none; width: 250px; }
        .search-input:focus { border-color: var(--brand); box-shadow: 0 0 0 2px rgba(107,66,38,0.1); }

        /* PRINT STYLESHEET */
        @media print {
            body { background: white; }
            body * { visibility: hidden; } 
            
            .no-print { display: none !important; }

            /* MODE 1: MASTER REPORT */
            #printable-area, #printable-area * { visibility: visible; }
            #printable-area { position: absolute; left: 0; top: 0; width: 100%; }
            .report-section { box-shadow: none; border: none; padding: 0; margin-bottom: 40px; height: auto; }
            .bento-grid { display: block; } 
            .data-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
            .data-table th, .data-table td { border: 1px solid #ccc; }

            /* MODE 2: PAYROLL LEDGER ONLY */
            body.print-payroll-mode @page { size: landscape; margin: 15mm; }
            body.print-payroll-mode #printable-area { visibility: hidden; } 
            body.print-payroll-mode #payroll-print-section, body.print-payroll-mode #payroll-print-section * { visibility: visible; }
            body.print-payroll-mode #payroll-print-section { position: absolute; left: 0; top: 0; width: 100%; margin: 0; font-family: 'Helvetica', sans-serif; }
            
            body.print-payroll-mode .print-only { display: table-cell !important; }
            body.print-payroll-mode .print-only-block { display: block !important; }
            
            body.print-payroll-mode .data-table { border: 2px solid black; }
            body.print-payroll-mode .data-table th, body.print-payroll-mode .data-table td { border: 1px solid #000; padding: 10px; font-size: 10pt; color: #000 !important; }
            body.print-payroll-mode .data-table th { background: #e2e8f0 !important; -webkit-print-color-adjust: exact; font-weight: bold; color: black !important; }
            body.print-payroll-mode h2.web-header { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print"><?php include '../components/navbar.php'; ?></div>

    <div class="report-layout">
        <div class="filter-bar no-print">
            <div class="filter-group">
                <div class="filter-item">
                    <label>Start Date</label>
                    <input type="date" id="r_start" value="<?= $start_date ?>">
                </div>
                <div class="filter-item">
                    <label>End Date</label>
                    <input type="date" id="r_end" value="<?= $end_date ?>">
                </div>
                <div style="display: flex; gap: 10px; align-items:flex-end;">
                    <button class="btn success" style="height:40px; padding:0 20px; font-weight:bold;" onclick="loadReport()">Generate</button>
                </div>
            </div>
            <div style="display: flex; gap: 10px; align-items:flex-end;">
                <button class="btn secondary" style="height:40px; padding:0 20px; border:1px solid #ccc; background:#f8fafc;" onclick="exportToCSV()">📥 Export Data</button>
                <button class="btn secondary" style="height:40px; padding:0 20px; border:2px dashed #ccc;" onclick="window.print()">🖨️ Print Full Report</button>
            </div>
        </div>

        <div id="printable-area">
            
            <div style="text-align:center; margin-bottom:30px;" class="print-only-block">
                <h1 style="margin:0;">Fogs Tasas Cafe - Analytics Report</h1>
                <p style="color:gray; font-size:1.1rem; margin:5px 0;">Period: <?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?></p>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Gross Sales</h3>
                    <div class="val">₱<?= number_format((float)$sales['raw_sales'], 2) ?></div>
                </div>
                <div class="summary-card danger">
                    <h3>Total Discounts Given</h3>
                    <div class="val" style="color:var(--danger);">-₱<?= number_format((float)$sales['discounts'], 2) ?></div>
                </div>
                <div class="summary-card" style="border-top-color:#10b981;">
                    <h3>Net Sales Revenue</h3>
                    <div class="val" style="color:#10b981;">₱<?= number_format((float)$sales['net_sales'], 2) ?></div>
                </div>
                <div class="summary-card" style="border-top-color:#6366f1;">
                    <h3>Completed Orders</h3>
                    <div class="val" style="color:#6366f1;"><?= $sales['total_orders'] ?></div>
                </div>
            </div>

            <div class="bento-grid">
                <div class="report-section" style="max-height: 400px; overflow-y: auto;">
                    <h2>🔥 Top 10 Best Sellers</h2>
                    <div>
                        <?php foreach($top_items as $idx => $ti): ?>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:15px; border-bottom:1px dashed #f1f5f9; padding-bottom:10px;">
                            <div style="width:30px; height:30px; background:<?= $idx===0?'#fef08a':($idx===1?'#e2e8f0':'#ffedd5') ?>; color:<?= $idx===0?'#854d0e':($idx===1?'#475569':'#9a3412') ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:0.9rem; flex-shrink:0;">
                                <?= $idx + 1 ?>
                            </div>
                            <div style="flex:1; overflow:hidden;">
                                <div style="font-weight:bold; color:var(--text-main);"><?= htmlspecialchars($ti['product_name']) ?></div>
                                <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;"><?= $ti['qty_sold'] ?> Units</div>
                            </div>
                            <div style="font-weight:900; color:var(--brand);">₱<?= number_format($ti['total_revenue'], 2) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($top_items)) echo "<div style='text-align:center; color:gray; font-weight:bold; padding:20px 0;'>No data available.</div>"; ?>
                    </div>
                </div>

                <div style="display:flex; flex-direction:column; gap:20px;">
                    <div class="report-section" style="margin:0; height:auto;">
                        <h2 style="font-size:1.1rem; margin-bottom:10px;">💳 Revenue Sources</h2>
                        
                        <div style="display:flex; gap:10px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom:15px;">
                            <?php foreach($payments as $p): ?>
                                <div style="flex:1; background:#f8fafc; padding:15px; border-radius:12px; border:1px solid #e2e8f0; text-align:center;">
                                    <div style="text-transform:uppercase; font-size:0.8rem; color:gray; font-weight:bold; margin-bottom:5px;">
                                        <?= $p['method'] === 'cash' ? '💵' : '📱' ?> <?= $p['method'] ?>
                                    </div>
                                    <div style="font-size:1.3rem; font-weight:900; color:var(--brand-dark);">₱<?= number_format((float)$p['net_amount'], 2) ?></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if(empty($payments)) echo "<div style='color:gray;'>No payments found.</div>"; ?>
                        </div>

                        <div style="display:flex; gap:10px;">
                            <?php foreach($cat_sales as $c): ?>
                                <div style="flex:1; text-align:center; padding:10px; background:#fff7ed; border-radius:8px; border:1px dashed #fdba74;">
                                    <div style="font-size:0.75rem; text-transform:uppercase; font-weight:bold; color:#9a3412;"><?= $c['cat_type'] === 'drink' ? '🍹 Drinks' : '🍔 Food' ?></div>
                                    <div style="font-size:1.1rem; font-weight:900; color:#c2410c;">₱<?= number_format($c['revenue'], 2) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="report-section" style="margin:0; flex:1; border-color:#fca5a5; background:#fef2f2;">
                        <h2 style="font-size:1.1rem; color:#b91c1c; border-color:#fecaca; margin-bottom:10px;">
                            <span>🗑️ Kitchen Waste / Voids</span>
                            <span style="font-size:1.3rem; font-weight:900;">₱<?= number_format($total_waste, 2) ?></span>
                        </h2>
                        <div style="max-height:150px; overflow-y:auto; padding-right:5px;">
                            <?php foreach($void_logs as $v): ?>
                                <div style="border-bottom:1px dashed #fca5a5; padding:8px 0; font-size:0.9rem;">
                                    <div style="display:flex; justify-content:space-between; font-weight:bold; color:#991b1b;">
                                        <span><?= $v['qty'] ?>x <?= htmlspecialchars($v['product_name']) ?></span>
                                        <span>₱<?= number_format($v['loss'], 2) ?></span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-size:0.8rem; color:#b91c1c; margin-top:3px;">
                                        <span><i>"<?= htmlspecialchars($v['reason']) ?>"</i></span>
                                        <span><?= date('M d, g:i A', strtotime($v['created_at'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if(empty($void_logs)) echo "<div style='color:#b91c1c; font-weight:bold; padding:10px 0;'>No kitchen waste recorded! 🎉</div>"; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="report-section full-width">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
                    <h2 style="margin:0; border:none; padding:0;">📦 Full Product Sales & Item Lookup</h2>
                    <input type="text" id="singleItemSearch" class="search-input no-print" placeholder="🔍 Search for a specific item..." onkeyup="filterItems()">
                </div>
                
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="data-table" id="itemsTable">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th style="text-align:center;">Total Qty Sold</th>
                                <th style="text-align:right;">Total Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($all_items as $item): ?>
                            <tr>
                                <td style="font-weight:bold; color:var(--text-main);"><?= htmlspecialchars($item['product_name']) ?></td>
                                <td style="text-align:center; font-weight:bold; color:var(--brand);"><?= $item['qty_sold'] ?></td>
                                <td style="text-align:right; font-weight:bold; color:#10b981;">₱<?= number_format($item['total_revenue'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($all_items)): ?>
                                <tr><td colspan="3" style="text-align:center; color:gray;">No items sold in this date range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-section full-width">
                <h2>💰 Cash Drawer / Shift Variances</h2>
                <div style="overflow-x: auto;">
                    <table class="data-table" id="shiftsTable">
                        <thead>
                            <tr>
                                <th>Date & Shift</th>
                                <th>Starting Float</th>
                                <th>Expected Cash</th>
                                <th>Counted Cash</th>
                                <th>Variance (Short/Over)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($shifts as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= date('M d - h:i A', strtotime($s['opened_at'])) ?></strong><br>
                                    <small style="color:gray;"><?= $s['opener'] ?> → <?= $s['closer'] ?? 'OPEN' ?></small>
                                </td>
                                <td>₱<?= number_format((float)$s['opening_cash'], 2) ?></td>
                                <td><?= isset($s['expected_cash']) ? '₱'.number_format((float)$s['expected_cash'], 2) : '-' ?></td>
                                <td><?= isset($s['actual_cash']) ? '₱'.number_format((float)$s['actual_cash'], 2) : '-' ?></td>
                                <td>
                                    <?php if(isset($s['variance'])): ?>
                                        <?php if($s['variance'] < 0): ?>
                                            <strong style="color:#c62828;">Short ₱<?= number_format(abs($s['variance']), 2) ?></strong>
                                        <?php elseif($s['variance'] > 0): ?>
                                            <strong style="color:#1d4ed8;">Over ₱<?= number_format($s['variance'], 2) ?></strong>
                                        <?php else: ?>
                                            <strong style="color:#15803d;">Perfect</strong>
                                        <?php endif; ?>
                                    <?php else: echo "-"; endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($shifts)) echo "<tr><td colspan='5' style='text-align:center;'>No shifts found.</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-section full-width" id="payroll-print-section" style="margin-bottom: 0;">
                
                <div class="print-only-block" style="text-align:center; margin-bottom:20px;">
                    <h1 style="margin:0; font-size:22pt; text-transform:uppercase; letter-spacing:2px;">Fogs Tasas Cafe</h1>
                    <h2 style="margin:5px 0; font-size:16pt; font-weight:normal; border-bottom:2px solid black; display:inline-block; padding-bottom:5px;">Official Payroll Ledger</h2>
                    <p style="margin:10px 0 0 0; font-weight:bold;">Period: <?= date('F d, Y', strtotime($start_date)) ?> to <?= date('F d, Y', strtotime($end_date)) ?></p>
                </div>

                <div class="no-print" style="display:flex; justify-content:space-between; align-items:center; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px;">
                    <div>
                        <h2 class="web-header" style="margin:0; color:var(--brand-dark); border:none; padding:0;">👥 Employee Wages & Payroll</h2>
                        <p style="color:gray; font-size:0.9rem; margin:5px 0 0 0;">Add bonuses or deductions below. The Net Pay will recalculate automatically.</p>
                    </div>
                    <button class="btn" style="background:#2e7d32; color:white; font-weight:bold; padding:10px 20px;" onclick="printPayrollLedger()">📝 Print DOLE Ledger</button>
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee Name</th>
                                <th style="text-align:center;">Shifts</th>
                                <th style="text-align:center;">Reg Hrs</th>
                                <th style="text-align:center; color:#c62828;">OT Hrs</th>
                                <th style="text-align:right;">Base Gross</th>
                                
                                <th class="no-print" style="text-align:center; color:#10b981;">Allowance (+)</th>
                                <th class="print-only" style="text-align:right;">Bonus (+)</th>
                                
                                <th class="no-print" style="text-align:center; color:#c62828;">Vale/Ded (-)</th>
                                <th class="print-only" style="text-align:right;">Ded. (-)</th>
                                
                                <th style="text-align:right;">Net Pay</th>
                                <th class="print-only" style="text-align:center;">Received By (Signature)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                $total_gross_cost = 0;
                                foreach($staff_pay as $uid => $s): 
                                    $regPay = $s['reg_hrs'] * $s['rate'];
                                    $otPay = $s['ot_hrs'] * ($s['rate'] * $rules['ot_multi']);
                                    $gross = $regPay + $otPay;
                                    $total_gross_cost += $gross;
                            ?>
                            <tr class="payroll-row" data-gross="<?= $gross ?>">
                                <td>
                                    <strong><?= mb_strtoupper((string)$s['name'], 'UTF-8') ?></strong><br>
                                    <small style="color:gray;">₱<?= number_format($s['rate'], 2) ?>/hr</small>
                                </td>
                                <td style="text-align:center;"><?= $s['shifts'] ?></td>
                                <td style="text-align:center;"><?= number_format($s['reg_hrs'], 2) ?></td>
                                <td style="text-align:center; color:#c62828; font-weight:bold;"><?= $s['ot_hrs'] > 0 ? number_format($s['ot_hrs'], 2) : '-' ?></td>
                                <td style="text-align:right; color:#475569;">₱<?= number_format($gross, 2) ?></td>
                                
                                <td class="no-print" style="text-align:center;">
                                    <input type="number" class="adj-input bonus-val" placeholder="0.00" onkeyup="recalcPayroll()" onchange="recalcPayroll()">
                                </td>
                                <td class="print-only print-bonus" style="text-align:right;">-</td>
                                
                                <td class="no-print" style="text-align:center;">
                                    <input type="number" class="adj-input ded-val" placeholder="0.00" onkeyup="recalcPayroll()" onchange="recalcPayroll()">
                                </td>
                                <td class="print-only print-ded" style="text-align:right;">-</td>
                                
                                <td style="text-align:right; font-weight:900; font-size:1.1rem; color:var(--brand-dark);">
                                    ₱<span class="row-net"><?= number_format($gross, 2) ?></span>
                                </td>

                                <td class="print-only" style="text-align:center; vertical-align:bottom; padding-bottom:5px;">
                                    <div style="width:150px; border-bottom:1px solid black; margin:20px auto 0 auto;"></div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if(empty($staff_pay)) echo "<tr><td colspan='11' style='text-align:center;'>No timesheets recorded for this period.</td></tr>"; ?>
                            
                            <?php if(!empty($staff_pay)): ?>
                            <tr style="background:#f1f5f9; border-top:2px solid #cbd5e1;">
                                <td colspan="5" style="text-align:right; font-weight:bold; font-size:1.1rem; text-transform:uppercase;">Total Net Payroll Release:</td>
                                <td class="no-print" colspan="2"></td>
                                <td class="print-only" colspan="2"></td>
                                <td style="text-align:right; font-weight:900; font-size:1.4rem; color:#2e7d32;" id="grand-net-total">₱<?= number_format($total_gross_cost, 2) ?></td>
                                <td class="print-only"></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="print-only-block" style="margin-top: 50px; text-align: left;">
                        <div style="display:inline-block; width: 45%;">
                            <p style="margin-bottom: 40px;">Prepared by:</p>
                            <div style="width:250px; border-bottom:1px solid black;"></div>
                            <p style="margin-top: 5px; font-weight:bold;">Admin / Manager</p>
                        </div>
                        <div style="display:inline-block; width: 45%; text-align: right;">
                            <p style="margin-bottom: 40px;">Approved and Released by:</p>
                            <div style="width:250px; border-bottom:1px solid black; display:inline-block;"></div>
                            <p style="margin-top: 5px; font-weight:bold; padding-right:50px;">Owner</p>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>

    <script>
        function loadReport() {
            const start = document.getElementById('r_start').value;
            const end = document.getElementById('r_end').value;
            window.location.href = `reports.php?start=${start}&end=${end}`;
        }

        // Live Item Filtering Logic
        function filterItems() {
            let input = document.getElementById("singleItemSearch").value.toLowerCase();
            let rows = document.querySelectorAll("#itemsTable tbody tr");
            rows.forEach(row => {
                if (row.cells.length < 3) return; // skip empty messages
                let text = row.cells[0].innerText.toLowerCase(); // only search product name
                row.style.display = text.includes(input) ? "" : "none";
            });
        }

        // Live calculation logic for the web inputs
        function recalcPayroll() {
            let grandNet = 0;
            document.querySelectorAll('.payroll-row').forEach(row => {
                let gross = parseFloat(row.getAttribute('data-gross')) || 0;
                let bonus = parseFloat(row.querySelector('.bonus-val').value) || 0;
                let ded = parseFloat(row.querySelector('.ded-val').value) || 0;
                
                let net = gross + bonus - ded;
                if (net < 0) net = 0; 
                
                row.querySelector('.row-net').innerText = net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                row.querySelector('.print-bonus').innerText = bonus > 0 ? bonus.toLocaleString('en-US', {minimumFractionDigits: 2}) : '-';
                row.querySelector('.print-ded').innerText = ded > 0 ? ded.toLocaleString('en-US', {minimumFractionDigits: 2}) : '-';
                
                grandNet += net;
            });
            document.getElementById('grand-net-total').innerText = '₱' + grandNet.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function printPayrollLedger() {
            recalcPayroll(); 
            document.body.classList.add('print-payroll-mode');
            window.print();
            
            setTimeout(() => {
                document.body.classList.remove('print-payroll-mode');
            }, 500);
        }

        function exportToCSV() {
            let csv = [];
            let rows = document.querySelectorAll("#shiftsTable tr");
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                csv.push(row.join(","));
            }
            let csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
            let downloadLink = document.createElement("a");
            downloadLink.download = "fogs_shifts_" + document.getElementById('r_start').value + ".csv";
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
        }
    </script>
</body>
</html>