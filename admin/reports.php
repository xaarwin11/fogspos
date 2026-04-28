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

// 2. FETCH TENDER BREAKDOWN (Cash vs GCash)
$payments = [];
if ($stmt = $mysqli->prepare("SELECT method, SUM(amount - change_given) as net_amount FROM payments WHERE DATE(created_at) >= ? AND DATE(created_at) <= ? GROUP BY method")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 3. FETCH CASH DRAWER / REGISTER SHIFTS
$shifts = [];
if ($stmt = $mysqli->prepare("SELECT r.*, u1.username as opener, u2.username as closer FROM register_shifts r LEFT JOIN users u1 ON r.opened_by = u1.id LEFT JOIN users u2 ON r.closed_by = u2.id WHERE DATE(r.opened_at) >= ? AND DATE(r.opened_at) <= ? ORDER BY r.opened_at DESC")) {
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $shifts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 4. FETCH PAYROLL RULES & TIMESHEETS
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
    
    if ($hrs <= 0) continue; // Skip ghost shifts
    
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
    <title>Master Reports & Payroll - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <style>
        .report-layout { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .filter-bar { background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 30px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { display: block; font-weight: bold; margin-bottom: 5px; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; }
        .filter-group input { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; }
        
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .summary-card { background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); border-top: 4px solid var(--brand); }
        .summary-card.danger { border-top-color: var(--danger); }
        .summary-card h3 { margin: 0 0 10px 0; color: var(--text-muted); font-size: 0.9rem; text-transform: uppercase; }
        .summary-card .val { font-size: 2.2rem; font-weight: 900; color: var(--text-main); margin: 0; }
        
        .report-section { background: white; padding: 25px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); margin-bottom: 30px; }
        .report-section h2 { margin-top: 0; color: var(--brand-dark); border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 20px; }
        
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .data-table th { background: #f9fafb; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; }
        .data-table tr:hover { background: #fdfaf6; }

        /* Inputs for Pay Adjustments */
        .adj-input { width: 80px; padding: 6px; border: 1px solid #ccc; border-radius: 6px; text-align: right; font-weight: bold; font-family: monospace; font-size: 1rem; }
        .adj-input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 5px rgba(107, 66, 38, 0.3); }

        /* Default hidden classes for print elements */
        .print-only, .print-only-block { display: none !important; }

        /* =================================================================
           PRINT STYLESHEET: Handles both Master Report AND Payroll Ledger
           ================================================================= */
        @media print {
            body { background: white; }
            body * { visibility: hidden; } /* Hide everything by default */
            
            .no-print { display: none !important; }

            /* --- MODE 1: MASTER REPORT (Default Print) --- */
            #printable-area, #printable-area * { visibility: visible; }
            #printable-area { position: absolute; left: 0; top: 0; width: 100%; }
            .report-section { box-shadow: none; border: none; padding: 0; margin-bottom: 40px; }
            .data-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
            .data-table th, .data-table td { border: 1px solid #ccc; }

            /* --- MODE 2: PAYROLL LEDGER ONLY (Triggered via JS class) --- */
            body.print-payroll-mode @page { size: landscape; margin: 15mm; }
            
            body.print-payroll-mode #printable-area { visibility: hidden; } /* Hide the rest of the report */
            
            body.print-payroll-mode #payroll-print-section, 
            body.print-payroll-mode #payroll-print-section * { visibility: visible; }
            body.print-payroll-mode #payroll-print-section { position: absolute; left: 0; top: 0; width: 100%; margin: 0; font-family: 'Helvetica', sans-serif; }
            
            body.print-payroll-mode .print-only { display: table-cell !important; }
            body.print-payroll-mode .print-only-block { display: block !important; }
            
            body.print-payroll-mode .data-table { border: 2px solid black; }
            body.print-payroll-mode .data-table th, 
            body.print-payroll-mode .data-table td { border: 1px solid #000; padding: 10px; font-size: 10pt; color: #000 !important; }
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
                <label>Start Date</label>
                <input type="date" id="r_start" value="<?= $start_date ?>">
            </div>
            <div class="filter-group">
                <label>End Date</label>
                <input type="date" id="r_end" value="<?= $end_date ?>">
            </div>
            <div style="display: flex; gap: 10px;">
                <button class="btn success" style="height:40px; padding:0 20px; font-weight:bold;" onclick="loadReport()">Generate Report</button>
                <button class="btn secondary" style="height:40px; padding:0 20px; border:2px dashed #ccc;" onclick="window.print()">🖨️ Print Master Report</button>
            </div>
        </div>

        <div id="printable-area">
            
            <div style="text-align:center; margin-bottom:30px;" class="print-only-block">
                <h1 style="margin:0;">Fogs Tasas Cafe - Master Report</h1>
                <p style="color:gray; font-size:1.1rem; margin:5px 0;">Period: <?= date('M d, Y', strtotime($start_date)) ?> to <?= date('M d, Y', strtotime($end_date)) ?></p>
            </div>

            <div class="summary-grid">
                <div class="summary-card">
                    <h3>Gross Sales (Before Discounts)</h3>
                    <div class="val">₱<?= number_format((float)$sales['raw_sales'], 2) ?></div>
                </div>
                <div class="summary-card danger">
                    <h3>Total Discounts Given</h3>
                    <div class="val" style="color:var(--danger);">-₱<?= number_format((float)$sales['discounts'], 2) ?></div>
                </div>
                <div class="summary-card" style="border-top-color:#10b981;">
                    <h3>Net Sales (Actual Revenue)</h3>
                    <div class="val" style="color:#10b981;">₱<?= number_format((float)$sales['net_sales'], 2) ?></div>
                </div>
                <div class="summary-card" style="border-top-color:#6366f1;">
                    <h3>Total Orders Completed</h3>
                    <div class="val" style="color:#6366f1;"><?= $sales['total_orders'] ?></div>
                </div>
            </div>

            <div class="charts-grid no-print">
                <div class="report-section" style="margin-bottom:0;">
                    <h2>📈 Daily Sales Trend</h2>
                    <canvas id="salesChart" height="100"></canvas>
                </div>
                <div class="report-section" style="margin-bottom:0;">
                    <h2>🍕 Category Distribution</h2>
                    <canvas id="categoryChart" height="200"></canvas>
                </div>
            </div>

            <div class="bento-grid">
                <div class="report-section" style="margin-bottom:0;">
                    <h2>💳 Payment Methods</h2>
                    <div style="display:flex; flex-direction:column; gap:15px;">
                        <?php foreach($payments as $p): ?>
                            <div style="background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                                <div style="text-transform:uppercase; font-size:0.9rem; color:gray; font-weight:bold;">
                                    <?= $p['method'] === 'cash' ? '💵' : '📱' ?> <?= $p['method'] ?>
                                </div>
                                <div style="font-size:1.3rem; font-weight:900; color:var(--brand-dark);">₱<?= number_format((float)$p['net_amount'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($payments)) echo "<div style='color:gray; padding:20px 0;'>No payments found for this period.</div>"; ?>
                    </div>
                </div>

                <div class="report-section" style="margin-bottom:0;">
                    <h2>🔥 Top 10 Best Sellers</h2>
                    <div>
                        <?php foreach($top_items as $idx => $ti): ?>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; border-bottom:1px dashed #f1f5f9; padding-bottom:8px;">
                            <div style="width:28px; height:28px; background:<?= $idx===0?'#fef08a':($idx===1?'#e2e8f0':'#ffedd5') ?>; color:<?= $idx===0?'#854d0e':($idx===1?'#475569':'#9a3412') ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:0.85rem; flex-shrink:0;">
                                <?= $idx + 1 ?>
                            </div>
                            <div style="flex:1; overflow:hidden;">
                                <div style="font-weight:bold; color:var(--text-main); font-size:0.95rem; white-space:nowrap; text-overflow:ellipsis; overflow:hidden;"><?= htmlspecialchars($ti['product_name']) ?></div>
                                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;"><?= $ti['qty_sold'] ?> Units Sold</div>
                            </div>
                            <div style="font-weight:900; color:var(--brand); font-size:1.05rem;">₱<?= number_format($ti['total_revenue'], 2) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($top_items)) echo "<div style='text-align:center; color:gray; font-weight:bold; padding:20px 0;'>No data available.</div>"; ?>
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
                                <tr><td colspan="3" style="text-align:center; color:gray; padding:20px;">No items sold in this date range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="report-section">
                <h2>💰 Cash Drawer / Shift Variances</h2>
                <div style="overflow-x: auto;">
                    <table class="data-table">
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

            <div class="report-section" id="payroll-print-section" style="margin-bottom: 0;">
                
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

        // Live calculation logic for the web inputs
        function recalcPayroll() {
            let grandNet = 0;
            document.querySelectorAll('.payroll-row').forEach(row => {
                let gross = parseFloat(row.getAttribute('data-gross')) || 0;
                let bonus = parseFloat(row.querySelector('.bonus-val').value) || 0;
                let ded = parseFloat(row.querySelector('.ded-val').value) || 0;
                
                let net = gross + bonus - ded;
                if (net < 0) net = 0; // Prevent negative pay
                
                // Update Web UI
                row.querySelector('.row-net').innerText = net.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                
                // Update hidden Print spans so they display cleanly on paper
                row.querySelector('.print-bonus').innerText = bonus > 0 ? bonus.toLocaleString('en-US', {minimumFractionDigits: 2}) : '-';
                row.querySelector('.print-ded').innerText = ded > 0 ? ded.toLocaleString('en-US', {minimumFractionDigits: 2}) : '-';
                
                grandNet += net;
            });
            document.getElementById('grand-net-total').innerText = '₱' + grandNet.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        // Dedicated function to print ONLY the Payroll Ledger
        function printPayrollLedger() {
            recalcPayroll(); // Ensure math is updated
            document.body.classList.add('print-payroll-mode');
            window.print();
            
            // Remove the class after the print dialog closes so the web view returns to normal
            setTimeout(() => {
                document.body.classList.remove('print-payroll-mode');
            }, 500);
        }
        
        // Live Item Filtering Logic
        function filterItems() {
            let input = document.getElementById("singleItemSearch").value.toLowerCase();
            let rows = document.querySelectorAll("#itemsTable tbody tr");
            rows.forEach(row => {
                if (row.cells.length < 3) return; 
                let text = row.cells[0].innerText.toLowerCase(); 
                row.style.display = text.includes(input) ? "" : "none";
            });
        }

        // --- CHART JS INITIALIZATION ---
        document.addEventListener('DOMContentLoaded', function() {
            // 1. Daily Sales Line Chart
            const salesCtx = document.getElementById('salesChart');
            if (salesCtx) {
                const dailyData = <?= json_encode($daily_sales) ?>;
                const labels = dailyData.map(d => {
                    const date = new Date(d.sale_date);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                });
                const revenues = dailyData.map(d => parseFloat(d.daily_revenue));

                new Chart(salesCtx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Gross Sales (₱)',
                            data: revenues,
                            borderColor: '#6B4226',
                            backgroundColor: 'rgba(107, 66, 38, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#6B4226'
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
            }

            // 2. Category Sales Doughnut Chart
            const catCtx = document.getElementById('categoryChart');
            if (catCtx) {
                const catData = <?= json_encode($cat_sales) ?>;
                const catLabels = catData.map(d => d.cat_type.toUpperCase());
                const catRevs = catData.map(d => parseFloat(d.revenue));

                new Chart(catCtx, {
                    type: 'doughnut',
                    data: {
                        labels: catLabels,
                        datasets: [{
                            data: catRevs,
                            backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#6366f1', '#8b5cf6'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'bottom' } },
                        cutout: '65%'
                    }
                });
            }
        });
    </script>
</body>
</html>