<?php
require_once '../db.php';
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager','staff'])) { 
    header("Location: ../pos/index.php"); 
    exit; 
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$role= $_SESSION['role'];
$mysqli = get_db_conn();

$date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
$display_date = date('D, M d, Y', strtotime($date));
$is_today = ($date === date('Y-m-d'));

$sales = ['total_orders' => 0, 'total_sales' => 0];
$payments = [];
$shifts = [];
$orders = [];
$top_items = [];
    
try {
    if ($stmt_top = $mysqli->prepare("SELECT product_name, SUM(quantity) as qty_sold, SUM(line_total) as total_revenue FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE DATE(o.paid_at) = ? AND o.status = 'paid' GROUP BY product_name ORDER BY qty_sold DESC LIMIT 5")) {
        $stmt_top->bind_param('s', $date);
        $stmt_top->execute();
        $top_items = $stmt_top->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_top->close();
    }
    
    if ($stmt1 = $mysqli->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(grand_total), 0) as total_sales, COALESCE(SUM(discount_total), 0) as total_discounts FROM orders WHERE DATE(paid_at) = ? AND status = 'paid'")) {
        $stmt1->bind_param('s', $date);
        $stmt1->execute();
        $sales = $stmt1->get_result()->fetch_assoc();
        $stmt1->close();
    }

    if ($stmt2 = $mysqli->prepare("SELECT method, SUM(amount - change_given) as net_amount FROM payments WHERE DATE(created_at) = ? GROUP BY method")) {
        $stmt2->bind_param('s', $date);
        $stmt2->execute();
        $payments = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    }

    if ($stmt_shift = $mysqli->prepare("SELECT r.*, u1.username as opener, u2.username as closer FROM register_shifts r LEFT JOIN users u1 ON r.opened_by = u1.id LEFT JOIN users u2 ON r.closed_by = u2.id WHERE DATE(r.opened_at) = ? ORDER BY r.opened_at DESC")) {
        $stmt_shift->bind_param('s', $date);
        $stmt_shift->execute();
        $shifts = $stmt_shift->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_shift->close();
    }

    if ($stmt3 = $mysqli->prepare("SELECT o.*, t.table_number FROM orders o LEFT JOIN tables t ON o.table_id = t.id WHERE DATE(COALESCE(o.paid_at, o.created_at)) = ? ORDER BY COALESCE(o.paid_at, o.created_at) DESC")) {
        $stmt3->bind_param('s', $date);
        $stmt3->execute();
        $orders = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt3->close();
    }
} catch (Exception $e) {
    echo "<div style='background:red; color:white; padding:10px;'>Database Error: " . $e->getMessage() . "</div>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sales Dashboard - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .dashboard-layout { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        
        .bento-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); position: relative; overflow: hidden; }
        .stat-card.hero { background: var(--brand); color: white; border: none; box-shadow: 0 10px 15px -3px rgba(107, 66, 38, 0.3); }
        .stat-card h3 { margin: 0 0 10px 0; color: var(--text-muted); font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card.hero h3 { color: rgba(255,255,255,0.8); }
        .stat-card .value { font-size: 2.5rem; font-weight: 900; color: var(--brand-dark); margin: 0; letter-spacing: -1px; }
        .stat-card.hero .value { color: white; }
        
        .section-panel { background: white; border-radius: 16px; padding: 25px; border: 1px solid var(--border); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); margin-bottom: 30px; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 10px; }
        .section-header h2 { margin: 0; color: var(--brand-dark); font-size: 1.25rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        
        .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .orders-table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .orders-table th, .orders-table td { padding: 15px 10px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .orders-table th { color: var(--text-muted); font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
        .orders-table tr:hover td { background: #fdfaf6; }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-open { background: #fef9c3; color: #c2410c; }
        .status-voided, .status-refunded { background: #fee2e2; color: #b91c1c; }
        
        .date-navigator { display: flex; align-items: center; background: white; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; width: fit-content; }
        .date-nav-btn { padding: 12px 18px; text-decoration: none; color: var(--brand); background: #f8fafc; font-weight: bold; font-size: 1.1rem; transition: 0.2s; }
        .date-display { padding: 12px 25px; font-weight: 800; color: var(--text-main); position: relative; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; }
        .hidden-date-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }

        .refund-row { display: flex; justify-content: space-between; padding: 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background 0.2s; align-items: center; }
        .refund-row input[type="checkbox"] { width: 22px; height: 22px; accent-color: #dc2626; cursor: pointer; flex-shrink: 0; }

        /* =========================================================
           MOBILE OPTIMIZATION (Samsung Galaxy A11 & Tablets)
           ========================================================= */
           
        /* 1. LANDSCAPE PHONES & SMALL TABLETS (e.g. 700px - 1024px) */
        /* Keeps columns side-by-side but tightens the gaps! */
        @media (max-width: 1024px) {
            .dashboard-layout { margin: 15px auto; padding: 0 10px; }
            .bento-grid { grid-template-columns: 1.6fr 1fr; gap: 15px; } 
            .section-panel { padding: 15px; margin-bottom: 15px; }
            .section-header { padding: 15px 15px 10px 15px !important; }
            .stats-grid { gap: 10px; }
            .stat-card { padding: 15px; }
            .stat-card .value { font-size: 1.8rem; }
            .table-responsive { padding: 0 15px 15px 15px !important; }
        }

        /* 2. PORTRAIT PHONES ONLY (e.g. < 650px) */
        /* This forces the stacking ONLY when you hold the phone upright */
        @media (max-width: 650px) {
            body { background-color: #f1f5f9; } 
            .bento-grid { grid-template-columns: 1fr; } /* Stacks the Tender Breakdown underneath */
            
            .dashboard-header { flex-direction: column; align-items: stretch; text-align: center; gap: 12px; }
            .dashboard-header h1 { font-size: 1.6rem !important; }
            
            .date-navigator { width: 100%; justify-content: space-between; }
            .date-display { flex: 1; justify-content: center; font-size: 1rem; padding: 10px; }
            .date-nav-btn { padding: 10px 20px; }

            .stats-grid { grid-template-columns: 1fr 1fr; }
            .stat-card.hero { grid-column: span 2; text-align: center; }

            .section-header > div { display: flex; flex-direction: column; width: 100%; gap: 8px; }
            .section-header input[type="text"] { width: 100% !important; box-sizing: border-box; }
            .section-header button { width: 100%; justify-content: center; }

            .orders-table { min-width: 500px; /* Forces horizontal swipe */ }
            .orders-table th, .orders-table td { padding: 10px 8px; font-size: 0.85rem; }
            .orders-table button { padding: 8px 12px !important; font-size: 0.8rem; margin: 2px 0; }
        }
    </style>
</head>
<body>
    <?php include '../components/navbar.php'; ?>

    <div class="dashboard-layout">
        <div class="dashboard-header">
            <div>
                <h1 style="color:var(--brand-dark); margin:0; margin-bottom:5px; font-size:2rem; font-weight:900;">Store Overview</h1>
                <label style="display:flex; align-items:center; gap:8px; font-weight:bold; color:var(--text-muted); cursor:pointer; font-size:0.85rem; text-transform:uppercase; justify-content: center;">
                    <input type="checkbox" id="autoRefreshToggle" onchange="toggleAutoRefresh()"> Live Auto-Refresh (60s)
                </label>
            </div>
            <div class="date-navigator">
                <a href="?date=<?= $prev_date ?>" class="date-nav-btn" style="border-right:1px solid var(--border);">❮</a>
                <form method="GET" style="margin:0; height:100%; flex: 1;">
                    <div class="date-display">
                        📅 <?= $is_today ? 'Today' : $display_date ?>
                        <input type="date" name="date" class="hidden-date-input" value="<?= $date ?>" onchange="this.form.submit()">
                    </div>
                </form>
                <a href="?date=<?= $next_date ?>" class="date-nav-btn" style="border-left:1px solid var(--border);">❯</a>
            </div>
        </div>

        <div class="stats-grid">
            <?php if (in_array($role, ['admin', 'manager'])): ?>
                <div class="stat-card hero">
                    <h3>Gross Sales</h3>
                    <div class="value">₱<?= number_format((float)($sales['total_sales'] ?? 0), 2) ?></div>
                    <div style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-top:5px; font-weight:bold;">For <?= date('M d, Y', strtotime($date)) ?></div>
                </div>
                <div class="stat-card">
                    <h3>Discounts Issued</h3>
                    <div class="value" style="color:#dc2626;">-₱<?= number_format((float)($sales['total_discounts'] ?? 0), 2) ?></div>
                    <div style="font-size:0.85rem; color:gray; margin-top:5px; font-weight:bold;">SC & PWD</div>
                </div>
            <?php else: ?>
                <div class="stat-card hero">
                    <h3>My Sales Target</h3>
                    <div class="value">🎯</div>
                    <div style="font-size:0.85rem; color:rgba(255,255,255,0.7); margin-top:5px;">Keep up the great work today!</div>
                </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h3>Orders</h3>
                <div class="value" style="color:#2563eb;"><?= $sales['total_orders'] ?? 0 ?></div>
                <div style="font-size:0.85rem; color:gray; margin-top:5px; font-weight:bold;">Successfully Paid</div>
            </div>
        </div>

        <div class="bento-grid">
            <div class="left-col">
                
                <div class="section-panel" style="padding:0; overflow:hidden;">
                    <div class="section-header" style="padding: 25px 25px 0 25px; border:none; margin-bottom:10px;">
                        <h2>📋 Order Ledger</h2>
                        <div style="display:flex; gap:10px; width: auto;">
                            <input type="text" id="orderSearch" onkeyup="filterOrders()" placeholder="🔍 Search Order..." style="padding:10px 15px; border-radius:8px; border:1px solid #ddd; outline:none; flex: 1;">
                            <button onclick="exportTableToCSV('fogs_sales_<?= $date ?>.csv')" class="btn secondary" style="background:#10b981; color:white; border:none;">📥 CSV</button>
                        </div>
                    </div>
                    <div class="table-responsive" style="padding:0 25px 25px 25px;">
                        <table class="orders-table" id="orderHistoryTable">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Time</th>
                                    <th>Type / Table</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($orders as $o): ?>
                                <?php 
                                    $minutes_open = round((time() - strtotime($o['created_at'])) / 60);
                                    $warning_color = ($o['status'] === 'open' && $minutes_open > 45) ? 'color:#dc2626; font-weight:900;' : 'font-weight:600;';
                                ?>
                                <tr>
                                    <td style="color:var(--brand-dark); font-weight:bold;">#<?= $o['id'] ?></td>
                                    <td style="color:var(--text-muted); font-size:0.9rem;"><b><?= date('h:i A', strtotime($o['created_at'])) ?></b></td>
                                    <td style="text-transform:capitalize; <?= $warning_color ?>">
                                        <?= $o['order_type'] === 'takeout' ? '🥡 Takeout' : '🍽️ Table ' . htmlspecialchars($o['table_number'] ?? 'N/A') ?>
                                        <?php if($o['status'] === 'open' && $minutes_open > 45) echo "<br><small style='color:#dc2626;'>Waiting {$minutes_open}m!</small>"; ?>
                                    </td>
                                    <td><span class="status-badge status-<?= htmlspecialchars($o['status'] ?? 'open') ?>"><?= htmlspecialchars($o['status'] ?? 'OPEN') ?></span></td>
                                    <td style="font-weight:900; color:var(--text-main); font-size:1.05rem;">₱<?= number_format((float)$o['grand_total'], 2) ?></td>
                                    <td style="text-align:right; white-space: nowrap;">
                                        <button onclick="viewOrderDetails(<?= $o['id'] ?>)" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold; transition:0.2s;">Receipt</button>
                                        <?php if(isset($o['status']) && $o['status'] === 'open'): ?>
                                            <button onclick="voidOpenOrder(<?= $o['id'] ?>)" style="background:#fef3c7; color:#b45309; border:1px solid #fde047; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold; margin-left:5px;">Void</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($orders)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:40px; color:gray; font-size:1.1rem; font-weight:bold;">No orders found for this date.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (in_array($role, ['admin', 'manager'])): ?>
                <div class="section-panel">
                    <div class="section-header">
                        <h2>💰 Cash Drawer Shifts</h2>
                        <div style="display:flex; gap:10px;">
                            <button class="btn" style="background:var(--brand); color:white; padding:10px 15px;" onclick="openRegisterPopup()">+ Open Shift</button>
                            <button class="btn" style="background:#dc2626; color:white; padding:10px 15px;" onclick="closeRegisterPopup()">🔒 Close Shift</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Starting Float</th>
                                    <th>Expected</th>
                                    <th>Counted</th>
                                    <th>Variance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($shifts as $s): ?>
                                <tr>
                                    <td>
                                        <strong><?= date('h:i A', strtotime($s['opened_at'])) ?></strong><br>
                                        <span style="font-size:0.75rem; color:gray; text-transform:uppercase; font-weight:bold;">Opener: <?= htmlspecialchars($s['opener'] ?? '?') ?></span>
                                    </td>
                                    <td style="font-weight:bold;">₱<?= number_format((float)$s['opening_cash'], 2) ?></td>
                                    <td><?= isset($s['expected_cash']) ? '₱'.number_format((float)$s['expected_cash'], 2) : '-' ?></td>
                                    <td><?= isset($s['actual_cash']) ? '₱'.number_format((float)$s['actual_cash'], 2) : '-' ?></td>
                                    <td>
                                        <?php if(isset($s['variance'])): ?>
                                            <?php if($s['variance'] < 0): ?>
                                                <strong style="color:#dc2626; background:#fef2f2; padding:4px 8px; border-radius:6px;">Short ₱<?= number_format(abs($s['variance']), 2) ?></strong>
                                            <?php elseif($s['variance'] > 0): ?>
                                                <strong style="color:#2563eb; background:#eff6ff; padding:4px 8px; border-radius:6px;">Over ₱<?= number_format($s['variance'], 2) ?></strong>
                                            <?php else: ?>
                                                <strong style="color:#16a34a; background:#f0fdf4; padding:4px 8px; border-radius:6px;">Perfect</strong>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="status-badge status-open">ACTIVE</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($shifts)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:20px; color:gray; font-weight:bold;">No shifts recorded.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <?php if (in_array($role, ['admin', 'manager'])): ?>
            <div class="right-col">
                
                <div class="section-panel" style="background:var(--bg-dark); border:1px solid #e2e8f0;">
                    <div class="section-header" style="border-bottom-color:#e2e8f0;">
                        <h2>💳 Tender Breakdown</h2>
                    </div>
                    <div>
                        <?php foreach($payments as $p): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; padding-bottom:12px; border-bottom:1px dashed #cbd5e1;">
                                <span style="text-transform:uppercase; color:var(--text-muted); font-weight:800; font-size:0.9rem; letter-spacing:1px;">
                                    <?= $p['method'] === 'cash' ? '💵' : ($p['method'] === 'gcash' ? '📱' : '💳') ?> <?= htmlspecialchars($p['method']) ?>
                                </span>
                                <span style="font-weight:900; font-size:1.2rem; color:var(--brand-dark);">₱<?= number_format((float)$p['net_amount'], 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($payments)) echo "<div style='color:gray; font-style:italic; text-align:center; padding:10px 0;'>No payments collected yet.</div>"; ?>
                    </div>
                </div>

                <div class="section-panel">
                    <div class="section-header">
                        <h2>🔥 Best Sellers</h2>
                    </div>
                    <div>
                        <?php foreach($top_items as $idx => $ti): ?>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:15px;">
                            <div style="width:30px; height:30px; background:<?= $idx===0?'#fef08a':($idx===1?'#e2e8f0':'#ffedd5') ?>; color:<?= $idx===0?'#854d0e':($idx===1?'#475569':'#9a3412') ?>; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:0.9rem; flex-shrink:0;">
                                <?= $idx + 1 ?>
                            </div>
                            <div style="flex:1; overflow:hidden;">
                                <div style="font-weight:bold; color:var(--text-main); line-height:1.2; text-overflow:ellipsis; white-space:nowrap; overflow:hidden;"><?= htmlspecialchars($ti['product_name']) ?></div>
                                <div style="font-size:0.8rem; color:var(--text-muted); font-weight:600;"><?= $ti['qty_sold'] ?> Units Sold</div>
                            </div>
                            <div style="font-weight:900; color:var(--brand);">₱<?= number_format($ti['total_revenue'], 0) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($top_items)) echo "<div style='text-align:center; color:gray; font-weight:bold; padding:20px 0;'>Not enough data.</div>"; ?>
                    </div>
                </div>

            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // --- AUTO REFRESH LOGIC ---
        // --- SEAMLESS LIVE POLLING LOGIC ---
        let refreshTimer;
        
        async function fetchLiveUpdates() {
            try {
                // Fetch the page in the background
                const res = await fetch(window.location.href);
                const text = await res.text();
                
                // Parse it into an invisible DOM
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, 'text/html');
                
                // 1. Update Stats Cards
                document.querySelector('.stats-grid').innerHTML = doc.querySelector('.stats-grid').innerHTML;
                
                // 2. Update Order History Table
                document.querySelector('#orderHistoryTable tbody').innerHTML = doc.querySelector('#orderHistoryTable tbody').innerHTML;
                filterOrders(); // Re-apply user's search text instantly!
                
                // 3. Update Shifts Table
                if (document.querySelector('#shiftsTable')) {
                    document.querySelector('#shiftsTable tbody').innerHTML = doc.querySelector('#shiftsTable tbody').innerHTML;
                }
                
                // 4. Update Best Sellers & Tender Breakdown (Right Column)
                if (document.querySelector('.right-col')) {
                    document.querySelector('.right-col').innerHTML = doc.querySelector('.right-col').innerHTML;
                }

            } catch(e) { console.error('Live update failed:', e); }
            
            // Schedule the next check
            if (sessionStorage.getItem('liveMode') === 'true') {
                refreshTimer = setTimeout(fetchLiveUpdates, 60000);
            }
        }

        function toggleAutoRefresh() {
            if (document.getElementById('autoRefreshToggle').checked) {
                sessionStorage.setItem('liveMode', 'true');
                fetchLiveUpdates(); // Trigger immediately, no reload!
            } else {
                sessionStorage.setItem('liveMode', 'false');
                clearTimeout(refreshTimer);
            }
        }
        
        // Start automatically on page load if checked
        if (sessionStorage.getItem('liveMode') === 'true') {
            document.getElementById('autoRefreshToggle').checked = true;
            refreshTimer = setTimeout(fetchLiveUpdates, 60000);
        }

        function filterOrders() {
            let input = document.getElementById("orderSearch").value.toLowerCase();
            let rows = document.querySelectorAll("#orderHistoryTable tbody tr");
            rows.forEach(row => {
                if (row.cells.length < 2) return; 
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(input) ? "" : "none";
            });
        }

        function exportTableToCSV(filename) {
            let csv = [];
            let rows = document.querySelectorAll("#orderHistoryTable tr");
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length - 1; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/"/g, '""');
                    row.push('"' + data + '"');
                }
                csv.push(row.join(","));
            }
            let csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
            let downloadLink = document.createElement("a");
            downloadLink.download = filename;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
        }

        async function reprintOrder(orderId) {
            Swal.fire({title: 'Printing...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
            try {
                const res = await fetch(`../api/print_order.php?order_id=${orderId}&type=receipt`);
                const data = await res.json();
                if (data.success) { Swal.fire({icon: 'success', title: 'Printed!', timer: 1000, showConfirmButton: false}); } 
                else { Swal.fire('Print Failed', data.error || 'Unknown error', 'error'); }
            } catch (e) { Swal.fire('Error', 'Could not reach printer service.', 'error'); }
        }

        async function voidOpenOrder(orderId) {
            Swal.fire({
                title: `Void Order #${orderId}?`,
                text: "This order is unpaid. Are you sure you want to void it entirely?",
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Void It', confirmButtonColor: '#f59e0b'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    try {
                        const res = await fetch('../api/clear_order.php', {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ order_id: orderId })
                        });
                        const data = await res.json();
                        if (data.success) {
                            Swal.fire({icon: 'success', title: 'Order Voided', timer: 1000, showConfirmButton: false}).then(() => window.location.reload());
                        } else { Swal.fire('Error', data.error || 'Could not void order.', 'error'); }
                    } catch(e) { Swal.fire('Error', 'Connection failed.', 'error'); }
                }
            });
        }
        
        async function viewOrderDetails(orderId) {
            try {
                Swal.fire({title: 'Loading Receipt...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                
                const res = await fetch(`../api/get_order_details.php?order_id=${orderId}`);
                const data = await res.json();
                
                if (!data.success) return Swal.fire('Error', data.error, 'error');
                
                const o = data.order;
                
                let html = `<div style="text-align:left; font-family:'Courier New', Courier, monospace; background:white; padding:15px; border-radius:4px; max-height:60vh; overflow-y:auto; font-size:0.9rem; border:1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">`;
                
                html += `<div style="text-align:center; font-weight:900; font-size:1.3rem; margin-bottom:15px; color:var(--text-main);">ORDER #${o.id}</div>`;
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Opened:</span> <span>${new Date(o.created_at).toLocaleString()}</span></div>`;
                if (o.paid_at) { html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Closed:</span> <span>${new Date(o.paid_at).toLocaleString()}</span></div>`; }
                
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Type:</span> <span style="font-weight:bold;">${o.order_type.toUpperCase()} ${o.table_number ? '(T-'+o.table_number+')' : ''}</span></div>`;
                if (o.cashier) html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Cashier:</span> <span>${o.cashier}</span></div>`;
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:15px;"><span>Status:</span> <span style="font-weight:900; color:${o.status === 'paid' ? '#16a34a' : (o.status === 'voided' ? '#dc2626' : '#d97706')}">${o.status.toUpperCase()}</span></div>`;

                html += `<div style="border-top:1px dashed #94a3b8; margin:15px 0;"></div>`;
                
                data.items.forEach(i => {
                    let name = i.variation_name ? `${i.product_name} (${i.variation_name})` : i.product_name;
                    let isRefunded = i.discount_note && i.discount_note.includes('[REFUNDED]');
                    
                    let textStyle = isRefunded ? 'text-decoration: line-through; color: #ef4444; opacity: 0.8;' : 'color: #0f172a;';
                    let badge = isRefunded ? `<div style="color:#dc2626; font-size:0.75rem; font-weight:bold; margin-top:2px;">[REFUNDED]</div>` : '';

                    html += `<div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
                                <div style="flex:1; padding-right:15px;">
                                    <span style="font-weight:bold; ${textStyle}">${i.quantity}x ${name}</span>
                                    ${badge}
                                </div>
                                <span style="font-weight:bold; ${textStyle}">₱${parseFloat(i.line_total).toFixed(2)}</span>
                             </div>`;
                    
                    i.modifiers.forEach(m => {
                        html += `<div style="color:#64748b; font-size:0.8rem; padding-left:15px; margin-top:-5px; margin-bottom:5px; ${textStyle}">+ ${m.name}</div>`;
                    });
                    
                    if (parseFloat(i.discount_amount) > 0 && !isRefunded) {
                        html += `<div style="color:#dc2626; font-size:0.8rem; padding-left:15px; margin-top:-5px; margin-bottom:5px;">- Disc: ₱${parseFloat(i.discount_amount).toFixed(2)} ${i.discount_note ? `(${i.discount_note})` : ''}</div>`;
                    }
                });
                
                html += `<div style="border-top:1px dashed #94a3b8; margin:15px 0;"></div>`;
                
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:5px;"><span>Subtotal:</span> <span>₱${parseFloat(o.subtotal).toFixed(2)}</span></div>`;
                if (parseFloat(o.discount_total) > 0) {
                    html += `<div style="display:flex; justify-content:space-between; color:#dc2626; margin-bottom:5px; font-weight:bold;"><span>Total Discount:</span> <span>-₱${parseFloat(o.discount_total).toFixed(2)}</span></div>`;
                }
                
                html += `<div style="display:flex; justify-content:space-between; font-weight:900; font-size:1.3rem; margin-top:10px; color:var(--text-main);"><span>TOTAL:</span> <span>₱${parseFloat(o.grand_total).toFixed(2)}</span></div>`;
                
                if (data.payments && data.payments.length > 0) {
                    html += `<div style="border-top:1px dashed #94a3b8; margin:15px 0;"></div>`;
                    data.payments.forEach(p => {
                        let isRefund = parseFloat(p.amount) < 0;
                        html += `<div style="display:flex; justify-content:space-between; ${isRefund ? 'color:#dc2626; font-weight:bold;' : 'color:#475569;'} margin-bottom:3px;"><span>${p.method.toUpperCase()} ${isRefund ? 'Refunded' : 'Tendered'}:</span> <span>₱${parseFloat(Math.abs(p.amount)).toFixed(2)}</span></div>`;
                    });
                }
                html += `</div>`; 
                
                Swal.fire({
                    title: false, html: html, width: '95%',
                    customClass: { popup: 'mobile-swal' },
                    showConfirmButton: true, confirmButtonText: 'Close', confirmButtonColor: '#475569',
                    showDenyButton: true, denyButtonText: '🖨️ Print', denyButtonColor: '#0f172a',
                    showCancelButton: o.status === 'paid', cancelButtonText: '↩️ Refund', cancelButtonColor: '#dc2626',
                }).then((result) => {
                    if (result.isDenied) { reprintOrder(orderId); } 
                    else if (result.dismiss === Swal.DismissReason.cancel) { openRefundModal(orderId, data.items); }
                });
                
            } catch(e) { Swal.fire('Error', 'Could not load receipt.', 'error'); }
        }

        window.updateRefundTotal = function() {
            let sum = 0;
            document.querySelectorAll('.ref-cb:checked').forEach(cb => {
                sum += parseFloat(cb.dataset.amount) || 0;
            });
            document.getElementById('ref-total-preview').innerText = '₱' + sum.toFixed(2);
        };

        window.openRefundModal = async function(orderId, items) {
            let hasRefundable = false;
            let rowsHtml = '';
            
            items.forEach(i => {
                let isAlreadyRefunded = i.discount_note && i.discount_note.includes('[REFUNDED]');
                if (parseFloat(i.line_total) > 0 && !isAlreadyRefunded) {
                    hasRefundable = true;
                    let name = i.variation_name ? `${i.product_name} (${i.variation_name})` : i.product_name;
                    rowsHtml += `
                        <label class="refund-row">
                            <div style="display: flex; align-items: center; gap: 12px; flex:1;">
                                <input type="checkbox" class="ref-cb" value="${i.id}" data-amount="${i.line_total}" onchange="updateRefundTotal()"> 
                                <div style="text-align:left;">
                                    <div style="font-weight: 700; color: #1e293b; line-height:1.2;">${i.quantity}x ${name}</div>
                                    ${i.modifiers.length > 0 ? `<div style="font-size:0.75rem; color:#64748b;">Includes addons</div>` : ''}
                                </div>
                            </div>
                            <span style="font-weight: 900; color: #dc2626; font-size:1.1rem;">₱${parseFloat(i.line_total).toFixed(2)}</span>
                        </label>`;
                }
            });

            if (!hasRefundable) return Swal.fire('Notice', 'There are no items left to refund on this receipt.', 'info');

            let checklistHtml = `
                <div style="background: white; border: 1px solid #cbd5e1; border-radius: 12px; overflow-y: auto; max-height: 40vh; margin-bottom: 15px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    ${rowsHtml}
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; background:#fef2f2; padding:15px; border-radius:12px; border:1px solid #fecaca; margin-bottom:20px;">
                    <span style="font-weight:bold; color:#991b1b; text-transform:uppercase; font-size:0.85rem;">Total to Refund</span>
                    <span id="ref-total-preview" style="font-weight:900; font-size:1.5rem; color:#dc2626;">₱0.00</span>
                </div>
            `;

            const { value: formValues } = await Swal.fire({
                title: '<div style="font-weight:900; color:#dc2626; font-size:1.2rem;">Refund Items</div>',
                html: `
                    ${checklistHtml}
                    <div style="text-align:left; margin-bottom:15px;">
                        <label style="font-size:0.8rem; font-weight:bold; color:#475569; text-transform:uppercase;">Reason for Refund</label>
                        <input type="text" id="ri-reason" class="swal2-input" placeholder="e.g. Spilled, Customer complaint" style="margin-top:5px; font-size:1rem;">
                    </div>
                    <div style="text-align:left;">
                        <label style="font-size:0.8rem; font-weight:bold; color:#475569; text-transform:uppercase;">Manager PIN</label>
                        <input type="password" id="ri-pin" class="swal2-input" placeholder="****" inputmode="numeric" style="margin-top:5px; text-align:center; font-size:1.5rem; letter-spacing:5px;">
                    </div>
                `,
                showCancelButton: true, confirmButtonText: 'Authorize', confirmButtonColor: '#dc2626', width: '95%',
                preConfirm: () => {
                    let selected = [];
                    document.querySelectorAll('.ref-cb:checked').forEach(cb => {
                        selected.push({ id: cb.value, amount: cb.dataset.amount });
                    });
                    if (selected.length === 0) { Swal.showValidationMessage('Select at least one item to refund.'); return false; }
                    
                    const reason = document.getElementById('ri-reason').value;
                    const pin = document.getElementById('ri-pin').value;
                    if (!reason || !pin) { Swal.showValidationMessage('Reason and Manager PIN are required.'); return false; }
                    return { items: selected, reason, pin };
                }
            });

            if (formValues) {
                Swal.fire({title:'Processing Refund...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
                const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                try {
                    const res = await fetch('../api/refund_items_batch.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ order_id: orderId, items: formValues.items, reason: formValues.reason, pin: formValues.pin })
                    });
                    const data = await res.json();
                    
                    if (data.success) {
                        Swal.fire('Refund Successful', 'The items have been voided and the cash drawer ledger has been updated.', 'success').then(() => {
                            viewOrderDetails(orderId);
                        });
                    } else { Swal.fire('Declined', data.error, 'error'); }
                } catch(e) { Swal.fire('Error', 'Connection failed.', 'error'); }
            }
        };
    </script>
</body>
</html>