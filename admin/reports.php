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
$total_payroll_cost = 0;
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
    <title>Master Reports - FogsTasa</title>
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
        
        @media print {
            body * { visibility: hidden; }
            .report-layout { margin: 0; padding: 0; }
            #printable-area, #printable-area * { visibility: visible; }
            #printable-area { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none !important; }
            .report-section { box-shadow: none; border: none; padding: 0; margin-bottom: 40px; }
            .data-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
            .data-table th, .data-table td { border: 1px solid #ccc; }
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
                <button class="btn secondary" style="height:40px; padding:0 20px; border:2px dashed #ccc;" onclick="window.print()">🖨️ Print A4</button>
            </div>
        </div>

        <div id="printable-area">
            <div style="text-align:center; margin-bottom:30px; display:none;" class="print-only">
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

            <div class="report-section">
                <h2>💳 Payment Methods</h2>
                <div style="display:flex; gap:30px; flex-wrap:wrap;">
                    <?php foreach($payments as $p): ?>
                        <div style="background:#f9fafb; padding:15px 25px; border-radius:8px; border:1px solid #eee; min-width:200px;">
                            <div style="text-transform:uppercase; font-size:0.85rem; color:gray; font-weight:bold; margin-bottom:5px;">
                                <?= $p['method'] === 'cash' ? '💵' : '📱' ?> <?= $p['method'] ?>
                            </div>
                            <div style="font-size:1.5rem; font-weight:900; color:var(--brand-dark);">₱<?= number_format((float)$p['net_amount'], 2) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if(empty($payments)) echo "<div style='color:gray;'>No payments found for this period.</div>"; ?>
                </div>
            </div>

            <div class="report-section">
                <h2>💰 Cash Drawer / Shift Variances</h2>
                <p style="color:gray; font-size:0.9rem; margin-top:-10px;">Tracks if cashiers are coming up short or over at the end of their shifts.</p>
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

            <div class="report-section" style="margin-bottom: 0;">
                <h2>👥 Employee Wages & Payroll</h2>
                <p style="color:gray; font-size:0.9rem; margin-top:-10px;">Auto-calculated based on your Timesheets and Hourly Rates.</p>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th style="text-align:center;">Shifts Worked</th>
                                <th style="text-align:center;">Regular Hrs</th>
                                <th style="text-align:center;">OT Hrs</th>
                                <th style="text-align:right;">Total Gross Wage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($staff_pay as $uid => $s): ?>
                            <?php 
                                $regPay = $s['reg_hrs'] * $s['rate'];
                                $otPay = $s['ot_hrs'] * ($s['rate'] * $rules['ot_multi']);
                                $gross = $regPay + $otPay;
                                $total_payroll_cost += $gross;
                            ?>
                            <tr>
                                <td><strong><?= $s['name'] ?></strong><br><small style="color:gray;">₱<?= number_format($s['rate'], 2) ?>/hr</small></td>
                                <td style="text-align:center;"><?= $s['shifts'] ?></td>
                                <td style="text-align:center;"><?= number_format($s['reg_hrs'], 2) ?></td>
                                <td style="text-align:center; color:#c62828; font-weight:bold;"><?= $s['ot_hrs'] > 0 ? number_format($s['ot_hrs'], 2) : '-' ?></td>
                                <td style="text-align:right; font-weight:bold; font-size:1.1rem; color:var(--brand-dark);">₱<?= number_format($gross, 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($staff_pay)) echo "<tr><td colspan='5' style='text-align:center;'>No timesheets recorded for this period.</td></tr>"; ?>
                            
                            <?php if(!empty($staff_pay)): ?>
                            <tr style="background:#f1f5f9; border-top:2px solid #cbd5e1;">
                                <td colspan="4" style="text-align:right; font-weight:bold; font-size:1.1rem; text-transform:uppercase;">Total Payroll Liability:</td>
                                <td style="text-align:right; font-weight:900; font-size:1.3rem; color:#c62828;">₱<?= number_format($total_payroll_cost, 2) ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <style> @media print { .print-only { display: block !important; } } </style>
        </div>
    </div>

    <script>
        function loadReport() {
            const start = document.getElementById('r_start').value;
            const end = document.getElementById('r_end').value;
            window.location.href = `reports.php?start=${start}&end=${end}`;
        }
    </script>
</body>
</html>