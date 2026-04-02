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
    
    if ($stmt1 = $mysqli->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(grand_total), 0) as total_sales, COALESCE(SUM(discount_total), 0) as total_discounts, COALESCE(SUM(tip_amount), 0) as total_tips FROM orders WHERE DATE(paid_at) = ? AND status = 'paid'")) {        $stmt1->bind_param('s', $date);
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
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#6B4226">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fogs POS">
    <link rel="apple-touch-icon" href="../assets/img/favicon.png">
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
        .status-pending { background: #fef08a; color: #854d0e; } /* ADDED PENDING STATUS COLOR */
        .status-preparing { background: #dbeafe; color: #1e3a8a; }
        .status-ready { background: #e0e7ff; color: #3730a3; }
        .status-voided, .status-refunded { background: #fee2e2; color: #b91c1c; }
        
        .date-navigator { display: flex; align-items: center; background: white; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; width: fit-content; }
        .date-nav-btn { padding: 12px 18px; text-decoration: none; color: var(--brand); background: #f8fafc; font-weight: bold; font-size: 1.1rem; transition: 0.2s; }
        .date-display { padding: 12px 25px; font-weight: 800; color: var(--text-main); position: relative; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; }
        .hidden-date-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        
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

        @media (max-width: 650px) {
            body { background-color: #f1f5f9; } 
            .bento-grid { grid-template-columns: 1fr; }
            
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

            .orders-table { min-width: 500px; }
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
                    <h3>Tips Collected</h3>
                    <div class="value" style="color:#16a34a;">₱<?= number_format((float)($sales['total_tips'] ?? 0), 2) ?></div>
                    <div style="font-size:0.85rem; color:gray; margin-top:5px; font-weight:bold;">Staff Gratuity</div>
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
                                    $warning_color = (in_array($o['status'], ['open', 'pending']) && $minutes_open > 45) ? 'color:#dc2626; font-weight:900;' : 'font-weight:600;';
                                ?>
                                <tr>
                                    <td style="color:var(--brand-dark); font-weight:bold;">#<?= $o['id'] ?></td>
                                    <td style="color:var(--text-muted); font-size:0.9rem;"><b><?= date('h:i A', strtotime($o['created_at'])) ?></b></td>
                                    <td style="text-transform:capitalize; <?= $warning_color ?>">
                                        <?= $o['order_type'] === 'takeout' ? '🥡 Takeout' : '🍽️ Table ' . htmlspecialchars($o['table_number'] ?? 'N/A') ?>
                                        <?php if(in_array($o['status'], ['open', 'pending']) && $minutes_open > 45) echo "<br><small style='color:#dc2626;'>Waiting {$minutes_open}m!</small>"; ?>
                                    </td>
                                    <td><span class="status-badge status-<?= htmlspecialchars($o['status'] ?? 'open') ?>"><?= htmlspecialchars($o['status'] ?? 'OPEN') ?></span></td>
                                    <td style="font-weight:900; color:var(--text-main); font-size:1.05rem;">₱<?= number_format((float)$o['grand_total'], 2) ?></td>
                                    
                                    <td style="text-align:right; white-space: nowrap;">
                                        <?php if($o['status'] === 'pending'): ?>
                                            <button onclick="updateOrderStatus(<?= $o['id'] ?>, 'preparing')" style="background:var(--brand); color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-weight:bold;">✅ Accept Web Order</button>
                                            <button onclick="voidOpenOrder(<?= $o['id'] ?>)" style="background:#dc2626; color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-weight:bold; margin-left:5px;">❌ Reject</button>

                                        <?php elseif($o['status'] === 'preparing'): ?>
                                            <button onclick="updateOrderStatus(<?= $o['id'] ?>, 'ready')" style="background:#f59e0b; color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-weight:bold;">🛎️ Mark Ready</button>

                                        <?php elseif($o['status'] === 'open' || $o['status'] === 'ready'): ?>
                                            <button onclick="viewOrderDetails(<?= $o['id'] ?>)" style="background:#16a34a; color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-weight:bold;">💳 View / Pay</button>
                                            <button onclick="voidOpenOrder(<?= $o['id'] ?>)" style="background:#dc2626; color:white; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-weight:bold; margin-left:5px;">🗑️ Void</button>

                                        <?php else: // paid, voided, refunded ?>
                                            <button onclick="viewOrderDetails(<?= $o['id'] ?>)" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold; transition:0.2s;">🧾 Receipt</button>
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
                        <table class="orders-table" id="shiftsTable">
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
        // --- SEAMLESS LIVE POLLING LOGIC ---
        let refreshTimer;
        
        async function fetchLiveUpdates() {
            try {
                const res = await fetch(window.location.href);
                const text = await res.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, 'text/html');
                
                document.querySelector('.stats-grid').innerHTML = doc.querySelector('.stats-grid').innerHTML;
                document.querySelector('#orderHistoryTable tbody').innerHTML = doc.querySelector('#orderHistoryTable tbody').innerHTML;
                filterOrders(); 
                
                if (document.querySelector('#shiftsTable')) {
                    document.querySelector('#shiftsTable tbody').innerHTML = doc.querySelector('#shiftsTable tbody').innerHTML;
                }
                if (document.querySelector('.right-col')) {
                    document.querySelector('.right-col').innerHTML = doc.querySelector('.right-col').innerHTML;
                }
            } catch(e) {}
            
            if (sessionStorage.getItem('liveMode') === 'true') {
                refreshTimer = setTimeout(fetchLiveUpdates, 60000);
            }
        }

        function toggleAutoRefresh() {
            if (document.getElementById('autoRefreshToggle').checked) {
                sessionStorage.setItem('liveMode', 'true');
                fetchLiveUpdates(); 
            } else {
                sessionStorage.setItem('liveMode', 'false');
                clearTimeout(refreshTimer);
            }
        }
        
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
                html: `
                    <div style="font-size:0.9rem; color:var(--danger); margin-bottom:15px; font-weight:bold;">
                        Please provide a reason and Manager PIN to void this order.
                    </div>
                    <input type="text" id="dash-void-reason" class="swal2-input" placeholder="Reason (e.g. Walk-out, Fake Order)">
                    <input type="password" id="dash-void-pin" class="swal2-input" placeholder="Manager PIN" inputmode="numeric">
                `,
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Void Order', confirmButtonColor: '#d33',
                preConfirm: () => {
                    const reason = document.getElementById('dash-void-reason').value;
                    const pin = document.getElementById('dash-void-pin').value;
                    if (!reason || !pin) { Swal.showValidationMessage('Reason and Manager PIN are required!'); return false; }
                    return { reason, pin };
                }
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    Swal.fire({title: 'Voiding...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                    try {
                        const res = await fetch('../api/clear_order.php', {
                            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, 
                            body: JSON.stringify({ order_id: orderId, reason: result.value.reason, pin: result.value.pin })
                        });
                        const data = await res.json();
                        if (data.success) {
                            Swal.fire({icon: 'success', title: 'Order Voided', timer: 1000, showConfirmButton: false}).then(() => fetchLiveUpdates());
                        } else { Swal.fire('Declined', data.error || 'Invalid PIN or error.', 'error'); }
                    } catch(e) { Swal.fire('Error', 'Connection failed.', 'error'); }
                }
            });
        }
        
        async function updateOrderStatus(orderId, newStatus) {
            try {
                const res = await fetch('../api/update_order_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order_id: orderId, status: newStatus })
                });
                const data = await res.json();
                
                if (data.success) {
                    fetchLiveUpdates(); 
                } else {
                    Swal.fire('Error', data.error || 'Could not update status.', 'error');
                }
            } catch(e) {
                Swal.fire('Error', 'Connection failed.', 'error');
            }
        }

        // --- NEW TIP FUNCTION ---
        async function addTip(orderId) {
            Swal.close();
            const { value: tipAmount } = await Swal.fire({
                title: 'Add Cash Tip',
                html: `
                    <div style="font-size:0.9rem; color:gray; margin-bottom:10px;">Enter the tip amount left on the table.</div>
                    <input type="number" id="tip-input" class="swal2-input" placeholder="Amount (₱)" step="0.01" style="font-size:1.5rem; text-align:center; font-weight:bold; color:var(--brand-dark);">
                `,
                showCancelButton: true, confirmButtonText: 'Save Tip', confirmButtonColor: '#2e7d32',
                preConfirm: () => {
                    const val = parseFloat(document.getElementById('tip-input').value);
                    if (isNaN(val) || val <= 0) { Swal.showValidationMessage('Enter a valid amount'); return false; }
                    return val;
                }
            });

            if (tipAmount) {
                Swal.fire({title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                try {
                    const res = await fetch('../api/add_tip.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ order_id: orderId, tip_amount: tipAmount })
                    });
                    const data = await res.json();
                    if (data.success) {
                        Swal.fire({icon: 'success', title: 'Tip Added!', timer: 1000, showConfirmButton: false}).then(() => {
                            viewOrderDetails(orderId);
                        });
                    } else { Swal.fire('Error', data.error, 'error'); }
                } catch(e) { Swal.fire('Error', 'Connection failed.', 'error'); }
            }
        }

        // --- RECEIPT WITH TIP DISPLAY ---
        async function viewOrderDetails(orderId) {
            try {
                Swal.fire({title: 'Loading Receipt...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                
                const res = await fetch(`../api/get_order_details.php?order_id=${orderId}`);
                const data = await res.json();
                
                if (!data.success) return Swal.fire('Error', data.error, 'error');
                
                const o = data.order;
                
                let html = `<div style="text-align:left; font-family:'Courier New', Courier, monospace; background:white; padding:15px; border-radius:4px; max-height:60vh; overflow-y:auto; font-size:0.9rem; border:1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">`;
                
                // Thermal Receipt Header
                html += `<div style="text-align:center; border-bottom: 2px dashed #94a3b8; padding-bottom: 10px; margin-bottom: 15px;">
                            <h2 style="margin: 0; font-size: 1.2rem; font-weight: 900; color:var(--text-main);">FOGS TASAS CAFE</h2>
                            <p style="margin: 2px 0 0 0; font-size: 0.8rem; font-weight: bold; color:var(--text-muted);">San Esteban, Ilocos Sur</p>
                         </div>`;
                
                // Order Info
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Order:</span> <span style="font-weight:900;">#${o.id}</span></div>`;
                if (o.reference && o.reference.startsWith('WEB-')) {
                    html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Ref:</span> <span style="font-weight:bold;">${o.reference}</span></div>`;
                }
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Opened:</span> <span>${new Date(o.created_at).toLocaleString()}</span></div>`;
                
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:4px;"><span>Type:</span> <span style="font-weight:bold;">${o.order_type.toUpperCase()} ${o.table_number ? '(T-'+o.table_number+')' : ''}</span></div>`;
                
                // Only show Customer Name IF one actually exists in the database
                if (o.customer_name && o.customer_name.trim() !== '') {
                    html += `<div style="display:flex; justify-content:space-between; margin-top: 8px; padding-top: 8px; border-top: 1px dotted #cbd5e1; margin-bottom:15px;">
                                <span>Customer:</span> 
                                <span style="font-weight:900; font-size:1.1rem; text-transform:uppercase; color:var(--brand-dark);">${o.customer_name}</span>
                             </div>`;
                } else {
                    // Just add a little spacing if there is no name row
                    html += `<div style="margin-top: 15px;"></div>`;
                }
                         
                html += `<div style="display:flex; justify-content:space-between; margin-bottom:15px;"><span>Status:</span> <span style="font-weight:900; color:${o.status === 'paid' ? '#16a34a' : (o.status === 'voided' ? '#dc2626' : (o.status === 'refunded' ? '#dc2626' : (o.status === 'pending' ? '#d97706' : '#2563eb')))}">${o.status.toUpperCase()}</span></div>`;

                if (o.status === 'voided' && data.void_logs && data.void_logs.length > 0) {
                    let mainReason = data.void_logs[data.void_logs.length - 1].reason; 
                    html += `<div style="margin-top: 5px; padding: 5px; background: #ffebee; border-left: 3px solid #c62828; margin-bottom:15px;"><strong>Void Reason:</strong> <span style="color:#c62828; font-weight:bold;">${mainReason}</span></div>`;
                } else if (o.status === 'refunded' && data.refund_logs && data.refund_logs.length > 0) {
                    let mainReason = data.refund_logs[data.refund_logs.length - 1].reason;
                    html += `<div style="margin-top: 5px; padding: 5px; background: #fef2f2; border-left: 3px solid #dc2626; margin-bottom:15px;"><strong>Refund Reason:</strong> <span style="color:#dc2626; font-weight:bold;">${mainReason}</span></div>`;
                }

                html += `<div style="border-top:1px dashed #94a3b8; margin:15px 0;"></div>`;
                
                data.items.forEach(i => {
                    let name = i.variation_name ? `${i.product_name} (${i.variation_name})` : i.product_name;
                    
                    let refQty = parseInt(i.refunded_qty) || 0;
                    let qty = parseInt(i.quantity);
                    let isFullyRefunded = refQty === qty;
                    let isPartiallyRefunded = refQty > 0 && refQty < qty;
                    let isOrderVoided = (o.status === 'voided'); 
                    
                    let textStyle = (isFullyRefunded || isOrderVoided) ? 'text-decoration: line-through; color: #ef4444; opacity: 0.8;' : 'color: #0f172a;';
                    
                    let badge = '';
                    if (isOrderVoided) {
                        badge = `<div style="color:#dc2626; font-size:0.75rem; font-weight:bold; margin-top:2px;">[VOIDED]</div>`;
                    } else if (isFullyRefunded) {
                        badge = `<div style="color:#dc2626; font-size:0.75rem; font-weight:bold; margin-top:2px;">[REFUNDED]</div>`;
                    } else if (isPartiallyRefunded) {
                        badge = `<div style="color:#f59e0b; font-size:0.75rem; font-weight:bold; margin-top:2px;">[${refQty} REFUNDED]</div>`;
                    }

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
                    
                    if (parseFloat(i.discount_amount) > 0) {
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

                // SHOW TIP
                if (parseFloat(o.tip_amount) > 0) {
                    html += `<div style="border-top:1px dashed #94a3b8; margin:15px 0;"></div>`;
                    html += `<div style="display:flex; justify-content:space-between; font-weight:bold; color:#16a34a; font-size:1.1rem;"><span>Staff Tip:</span> <span>₱${parseFloat(o.tip_amount).toFixed(2)}</span></div>`;
                }

                // TIP BUTTON FOR PAID ORDERS
                if (o.status === 'paid') {
                     html += `
                     <div style="margin-top:20px;">
                        <button onclick="addTip(${o.id})" style="width:100%; padding:12px; background:#f0fdf4; color:#166534; border:2px dashed #bbf7d0; border-radius:8px; font-weight:bold; cursor:pointer; font-size:1rem;">
                            💚 Add Cash Tip Left on Table
                        </button>
                     </div>`;
                }

                if (data.refund_logs && data.refund_logs.length > 0) {
                    html += `<div style="border-top:2px solid #dc2626; margin:15px 0 10px 0;"></div>`;
                    html += `<div style="margin-bottom:8px; font-weight:900; color:#dc2626; text-transform:uppercase; font-size:0.8rem;">Refund History</div>`;
                    
                    data.refund_logs.forEach(log => {
                        html += `
                        <div style="background:#fef2f2; border-left:3px solid #dc2626; padding:10px; margin-bottom:8px; border-radius:0 4px 4px 0;">
                            <div style="display:flex; justify-content:space-between; font-weight:bold; color:#b91c1c; margin-bottom:3px;">
                                <span>₱${parseFloat(log.total_amount).toFixed(2)} Refunded</span>
                                <span style="font-size:0.8rem;">${new Date(log.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                            </div>
                            <div style="color:#7f1d1d; font-size:0.85rem;"><strong>By:</strong> ${log.manager_name}</div>
                            <div style="color:#991b1b; font-size:0.85rem; font-style:italic;">"${log.reason}"</div>
                        </div>`;
                    });
                }

                if (o.status !== 'voided' && data.void_logs && data.void_logs.length > 0) {
                    html += `<div style="border-top:2px solid #f59e0b; margin:15px 0 10px 0;"></div>`;
                    html += `<div style="margin-bottom:8px; font-weight:900; color:#b45309; text-transform:uppercase; font-size:0.8rem;">Kitchen Voids (Waste)</div>`;
                    
                    data.void_logs.forEach(log => {
                        html += `
                        <div style="background:#fffbeb; border-left:3px solid #f59e0b; padding:10px; margin-bottom:8px; border-radius:0 4px 4px 0;">
                            <div style="display:flex; justify-content:space-between; font-weight:bold; color:#b45309; margin-bottom:3px;">
                                <span>Removed Items</span>
                                <span style="font-size:0.8rem;">${new Date(log.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                            </div>
                            <div style="color:#92400e; font-size:0.85rem; font-weight:bold; margin-bottom:3px;">${log.voided_summary}</div>
                            <div style="color:#78350f; font-size:0.85rem;"><strong>By:</strong> ${log.manager_name || 'System'}</div>
                            <div style="color:#92400e; font-size:0.85rem; font-style:italic;">"${log.reason}"</div>
                        </div>`;
                    });
                }

                html += `</div>`; 
                
                Swal.fire({
                    title: false, html: html, width: 420,
                    showConfirmButton: true, confirmButtonText: 'Close', confirmButtonColor: '#475569',
                    showDenyButton: true, denyButtonText: '🖨️ Print', denyButtonColor: '#0f172a',
                    showCancelButton: o.status === 'paid', cancelButtonText: '↩️ Refund', cancelButtonColor: '#dc2626',
                }).then((result) => {
                    if (result.isDenied) { reprintOrder(orderId); } 
                    else if (result.dismiss === Swal.DismissReason.cancel) { openRefundModal(orderId, data.items, o.grand_total); }
                });
                
            } catch(e) { Swal.fire('Error', 'Could not load receipt.', 'error'); }
        }

        window.adjRefQty = function(btn, delta, max) {
            let input = btn.parentElement.querySelector('.ref-qty');
            let val = parseInt(input.value) + delta;
            if (val < 0) val = 0;
            if (val > max) val = max;
            input.value = val;
            updateRefundTotal();
        };

        window.updateRefundTotal = function() {
            let sum = 0;
            document.querySelectorAll('.ref-qty').forEach(input => {
                let q = parseInt(input.value) || 0;
                let u = parseFloat(input.dataset.unitprice) || 0;
                sum += (q * u);
            });
            document.getElementById('ref-total-preview').innerText = '₱' + sum.toFixed(2);
        };

        window.refundOrder = async function(orderId, amount) {
            Swal.close();
            const { value: formValues } = await Swal.fire({
                title: `⚠️ Refund Entire Bill`,
                html: `
                    <div style="margin-bottom:15px; font-size:0.9rem; color:gray;">Refunding will return <b>₱${parseFloat(amount).toFixed(2)}</b> and update the Z-Report.</div>
                    <div style="text-align:left; margin-bottom:15px;">
                        <label style="font-size:0.8rem; font-weight:bold; color:#475569; text-transform:uppercase;">Reason for Refund</label>
                        <input type="text" id="ref-reason" placeholder="e.g. Cold Food, Mistake" style="width: 100%; box-sizing: border-box; padding: 12px; margin-top: 5px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; outline: none; font-family: inherit;">
                    </div>
                    <div style="text-align:center;">
                        <label style="font-size:0.8rem; font-weight:bold; color:#475569; text-transform:uppercase;">Manager PIN</label>
                        <input type="password" id="ref-pin" placeholder="****" inputmode="numeric" style="width: 140px; box-sizing: border-box; padding: 12px; margin: 8px auto 0 auto; display: block; text-align: center; font-size: 1.5rem; letter-spacing: 8px; border: 2px solid #cbd5e1; border-radius: 8px; outline: none; font-family: monospace;">
                    </div>
                `,
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Authorize Full Refund', confirmButtonColor: '#dc2626',
                preConfirm: () => {
                    const reason = document.getElementById('ref-reason').value;
                    const pin = document.getElementById('ref-pin').value;
                    if (!reason || !pin) { Swal.showValidationMessage('Both Reason and Manager PIN are required!'); return false; }
                    return { reason, pin };
                }
            });

            if (formValues) {
                const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                Swal.fire({title:'Processing Refund...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
                try {
                    const res = await fetch('../api/refund_order.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ order_id: orderId, pin: formValues.pin, reason: formValues.reason })
                    });
                    const text = await res.text(); 
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            Swal.fire({icon: 'success', title: 'Refund Processed', timer: 2000, showConfirmButton: false}).then(() => fetchLiveUpdates());
                        } else {
                            Swal.fire('Declined', data.error, 'error');
                        }
                    } catch (err) {
                        Swal.fire('Server Error', 'Raw response: ' + text.substring(0, 100), 'error');
                    }
                } catch(e) { Swal.fire('Network Error', 'Could not reach server.', 'error'); }
            }
        };

        window.openRefundModal = async function(orderId, items, grandTotal) {
            let hasRefundable = false;
            let rowsHtml = '';
            
            items.forEach(i => {
                if (parseFloat(i.line_total) > 0) {
                    hasRefundable = true;
                    let maxQty = parseInt(i.quantity);
                    let unitPrice = parseFloat(i.line_total) / maxQty;
                    let name = i.variation_name ? `${i.product_name} (${i.variation_name})` : i.product_name;
                    
                    rowsHtml += `
                        <div class="refund-row" style="display:flex; justify-content:space-between; padding:15px; border-bottom:1px solid #f1f5f9; align-items:center;">
                            <div style="flex:1; text-align:left; padding-right:10px;">
                                <div style="font-weight: 700; color: #1e293b; line-height:1.2;">${name}</div>
                                <div style="color: #dc2626; font-weight:bold; font-size:0.85rem;">₱${unitPrice.toFixed(2)} each</div>
                            </div>
                            <div style="display:flex; align-items:center; gap:5px; background:#f1f5f9; padding:5px; border-radius:8px; border:1px solid #e2e8f0;">
                                <button type="button" onclick="adjRefQty(this, -1, ${maxQty})" style="width:30px; height:30px; border-radius:4px; border:1px solid #cbd5e1; background:white; font-weight:bold; cursor:pointer; color:var(--text-main);">−</button>
                                <input type="number" class="ref-qty" data-id="${i.id}" data-unitprice="${unitPrice}" value="0" min="0" max="${maxQty}" readonly style="width:30px; text-align:center; font-weight:bold; border:none; background:transparent; font-size:1.1rem; color:var(--brand-dark);">
                                <button type="button" onclick="adjRefQty(this, 1, ${maxQty})" style="width:30px; height:30px; border-radius:4px; border:1px solid #cbd5e1; background:white; font-weight:bold; cursor:pointer; color:var(--text-main);">+</button>
                            </div>
                        </div>`;
                }
            });

            if (!hasRefundable) return Swal.fire('Notice', 'There are no items left to refund on this receipt.', 'info');

            let checklistHtml = `
                <button type="button" onclick="refundOrder(${orderId}, ${grandTotal})" style="width:100%; background:#dc2626; color:white; padding:12px; border-radius:8px; border:none; font-weight:bold; font-size:1rem; margin-bottom:15px; cursor:pointer;">⚠️ Refund Entire Bill</button>
                
                <div style="font-size:0.85rem; font-weight:bold; color:gray; margin-bottom:5px; text-align:left; text-transform:uppercase;">Or Refund Specific Quantities:</div>
                <div style="background: white; border: 1px solid #cbd5e1; border-radius: 12px; overflow-y: auto; max-height: 35vh; margin-bottom: 15px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                    ${rowsHtml}
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center; background:#fef2f2; padding:15px; border-radius:12px; border:1px solid #fecaca; margin-bottom:20px;">
                    <span style="font-weight:bold; color:#991b1b; text-transform:uppercase; font-size:0.85rem;">Total to Refund</span>
                    <span id="ref-total-preview" style="font-weight:900; font-size:1.5rem; color:#dc2626;">₱0.00</span>
                </div>
            `;

            const { value: formValues } = await Swal.fire({
                title: '<div style="font-weight:900; color:#1e293b; font-size:1.2rem;">Issue Refund</div>',
                html: `
                    ${checklistHtml}
                    <div style="text-align:left; margin-bottom:15px;">
                        <label style="font-size:0.8rem; font-weight:bold; color:#475569; text-transform:uppercase;">Reason for Refund</label>
                        <input type="text" id="ri-reason" placeholder="e.g. Spilled, Customer complaint" style="width: 100%; box-sizing: border-box; padding: 12px; margin-top: 5px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; outline: none; font-family: inherit;">
                    </div>
                    <div style="text-align:center;">
                        <label style="font-size:0.8rem; font-weight:bold; color:#475569; text-transform:uppercase;">Manager PIN</label>
                        <input type="password" id="ri-pin" placeholder="****" inputmode="numeric" style="width: 140px; box-sizing: border-box; padding: 12px; margin: 8px auto 0 auto; display: block; text-align: center; font-size: 1.5rem; letter-spacing: 8px; border: 2px solid #cbd5e1; border-radius: 8px; outline: none; font-family: monospace;">
                    </div>
                `,
                showCancelButton: true, confirmButtonText: 'Authorize Partial Refund', confirmButtonColor: '#6B4226', width: 420,
                preConfirm: () => {
                    let selected = [];
                    document.querySelectorAll('.ref-qty').forEach(input => {
                        let q = parseInt(input.value) || 0;
                        if (q > 0) {
                            let u = parseFloat(input.dataset.unitprice);
                            selected.push({ id: input.dataset.id, qty: q, amount: q * u });
                        }
                    });
                    
                    if (selected.length === 0) { Swal.showValidationMessage('Increase an item quantity to refund, or use "Refund Entire Bill".'); return false; }
                    
                    const reason = document.getElementById('ri-reason').value;
                    const pin = document.getElementById('ri-pin').value;
                    if (!reason || !pin) { Swal.showValidationMessage('Reason and Manager PIN are required.'); return false; }
                    return { items: selected, reason, pin };
                }
            });

            if (formValues) {
                const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                Swal.fire({title:'Processing Refund...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
                try {
                    const res = await fetch('../api/refund_items_batch.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ order_id: orderId, items: formValues.items, reason: formValues.reason, pin: formValues.pin })
                    });
                    const text = await res.text();
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            Swal.fire('Refund Successful', 'The items have been voided and the cash drawer ledger has been updated.', 'success').then(() => {
                                viewOrderDetails(orderId);
                            });
                        } else { Swal.fire('Declined', data.error, 'error'); }
                    } catch (err) {
                        Swal.fire('Server Error', 'Raw response: ' + text.substring(0, 100), 'error');
                    }
                } catch(e) { Swal.fire('Network Error', 'Could not reach server.', 'error'); }
            }
        };
    </script>
</body>
</html>