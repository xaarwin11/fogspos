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
    
    // Sales math is now calculated based strictly on the PAID_AT date
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

    // Show orders that were EITHER created today, or paid today.
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Dashboard - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .dashboard-layout { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); }
        .stat-card h3 { margin: 0 0 10px 0; color: var(--text-muted); font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 2.8rem; font-weight: 800; color: var(--brand); margin: 0; }
        
        .orders-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); margin-bottom: 30px; }
        .orders-table th, .orders-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .orders-table th { background: var(--bg-dark); color: var(--brand-dark); font-weight: 700; text-transform: uppercase; font-size: 0.85rem; }
        .orders-table tr:hover { background: #fdfaf6; }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; }
        .status-paid { background: #e8f5e9; color: #2e7d32; }
        .status-open { background: #fff3e0; color: #e65100; }
        .status-voided, .status-refunded { background: #ffebee; color: #c62828; }
        
        .date-navigator { display: flex; align-items: center; background: white; border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow); overflow: hidden; }
        .date-nav-btn { padding: 10px 15px; text-decoration: none; color: var(--brand); background: #f9fafb; font-weight: bold; font-size: 1.1rem; transition: 0.2s; }
        .date-nav-btn:hover { background: #e5e7eb; }
        .date-display { padding: 10px 20px; font-weight: bold; color: var(--text-main); position: relative; cursor: pointer; display: flex; align-items: center; gap: 8px; }
        .date-display:hover { background: #fafafa; }
        .hidden-date-input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }

        @media (max-width: 768px) {
            .dashboard-header { flex-direction: column; align-items: stretch; gap: 15px; text-align: center; }
            .date-navigator { width: 100%; display: flex; justify-content: space-between; }
            .date-display { flex: 1; justify-content: center; }
            .stat-card { padding: 15px; }
            .stat-card .value { font-size: 2.2rem; }
            .search-bar-container { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>
    <?php include '../components/navbar.php'; ?>

    <div class="dashboard-layout">
        <div class="dashboard-header">
            <div>
                <h1 style="color:var(--brand-dark); margin:0; margin-bottom:5px;">Sales Dashboard</h1>
                <label style="display:flex; align-items:center; gap:8px; font-weight:bold; color:var(--brand); cursor:pointer; font-size:0.9rem;">
                    <input type="checkbox" id="autoRefreshToggle" onchange="toggleAutoRefresh()"> Live Auto-Refresh
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
                <div class="stat-card">
                    <h3>Gross Sales</h3>
                    <div class="value">₱<?= number_format((float)($sales['total_sales'] ?? 0), 2) ?></div>
                    <div style="font-size:0.9rem; color:gray; margin-top:5px;">For <?= date('F d, Y', strtotime($date)) ?></div>
                </div>
                <div class="stat-card">
                    <h3>Discounts Given</h3>
                    <div class="value" style="color:var(--danger);">₱<?= number_format((float)($sales['total_discounts'] ?? 0), 2) ?></div>
                    <div style="font-size:0.9rem; color:gray; margin-top:5px;">SC, PWD, and Custom</div>
                </div>
            <?php else: ?>
                <div class="stat-card">
                    <h3>My Sales Target</h3>
                    <div class="value">🎯</div>
                    <div style="font-size:0.9rem; color:gray; margin-top:5px;">Keep up the great work today!</div>
                </div>
            <?php endif; ?>
            
            <div class="stat-card">
                <h3>Completed Orders</h3>
                <div class="value"><?= $sales['total_orders'] ?? 0 ?></div>
                <div style="font-size:0.9rem; color:gray; margin-top:5px;">Successfully paid and closed</div>
            </div>
            
            <?php if (in_array($role, ['admin', 'manager'])): ?>
                <div class="stat-card">
                    <h3>Tender Breakdown</h3>
                    <div style="margin-top:10px;">
                        <?php foreach($payments as $p): ?>
                            <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px dashed #eee; padding-bottom:5px;">
                                <span style="text-transform:capitalize; color:var(--text-muted); font-weight:600;">
                                    <?= $p['method'] === 'cash' ? '💵' : ($p['method'] === 'gcash' ? '📱' : '💳') ?> <?= htmlspecialchars($p['method']) ?>
                                </span>
                                <span style="font-weight:bold; font-size:1.1rem; color:var(--text-main);">₱<?= number_format((float)$p['net_amount'], 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($payments)) echo "<span style='color:gray; font-style:italic;'>No payments recorded yet.</span>"; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (in_array($role, ['admin', 'manager'])): ?>
            
            <div style="margin-bottom: 30px;">
                <h2 style="color:var(--brand-dark); margin-bottom:15px;">🔥 Top 5 Best Sellers</h2>
                <div class="table-responsive">
                    <table class="orders-table" style="margin-bottom:0;">
                        <thead><tr><th>Item Name</th><th>Quantity Sold</th><th>Total Revenue</th></tr></thead>
                        <tbody>
                            <?php foreach($top_items as $ti): ?>
                            <tr>
                                <td style="font-weight:bold;"><?= htmlspecialchars($ti['product_name']) ?></td>
                                <td><?= $ti['qty_sold'] ?>x</td>
                                <td style="color:var(--brand); font-weight:bold;">₱<?= number_format($ti['total_revenue'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($top_items)) echo "<tr><td colspan='3' style='text-align:center; color:gray;'>No items sold yet.</td></tr>"; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h2 style="color:var(--brand-dark); margin:0;">💰 Cash Drawer Shifts</h2>
                <div>
                    <button class="btn" style="background:var(--brand); color:white;" onclick="openRegisterPopup()">+ Open Register</button>
                    <button class="btn" style="background:#c62828; color:white;" onclick="closeRegisterPopup()">🔒 Close Register</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Opened</th>
                            <th>Float</th>
                            <th>Closed</th>
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
                                <span style="font-size:0.8rem; color:gray;">By <?= htmlspecialchars($s['opener'] ?? 'Unknown') ?></span>
                            </td>
                            <td>₱<?= number_format((float)$s['opening_cash'], 2) ?></td>
                            <td>
                                <?php if(isset($s['status']) && $s['status'] === 'open'): ?>
                                    <span class="status-badge status-open">CURRENTLY OPEN</span>
                                <?php else: ?>
                                    <strong><?= date('h:i A', strtotime($s['closed_at'] ?? 'now')) ?></strong><br>
                                    <span style="font-size:0.8rem; color:gray;">By <?= htmlspecialchars($s['closer'] ?? 'Unknown') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= isset($s['expected_cash']) ? '₱'.number_format((float)$s['expected_cash'], 2) : '-' ?></td>
                            <td><?= isset($s['actual_cash']) ? '₱'.number_format((float)$s['actual_cash'], 2) : '-' ?></td>
                            <td>
                                <?php if(isset($s['variance'])): ?>
                                    <?php if($s['variance'] < 0): ?>
                                        <strong style="color:red;">Short ₱<?= number_format(abs($s['variance']), 2) ?></strong>
                                    <?php elseif($s['variance'] > 0): ?>
                                        <strong style="color:blue;">Over ₱<?= number_format($s['variance'], 2) ?></strong>
                                    <?php else: ?>
                                        <strong style="color:green;">Perfect</strong>
                                    <?php endif; ?>
                                <?php else: echo "-"; endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($shifts)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:20px; color:gray;">No register shifts recorded for this date.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
            <h2 style="color:var(--brand-dark); margin:0;">Order History</h2>
            <div class="search-bar-container" style="display:flex; gap:10px; align-items:center;">
                <input type="text" id="orderSearch" onkeyup="filterOrders()" placeholder="🔍 Search Order #, Table, or Status..." class="search-bar" style="width:280px; margin-bottom:0; padding:10px;">
                <button onclick="exportTableToCSV('fogs_sales_<?= $date ?>.csv')" class="btn secondary" style="background:#10b981; color:white; border:none; padding:10px 15px; white-space:nowrap;">📥 Export CSV</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="orders-table" id="orderHistoryTable">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Time</th>
                        <th>Type / Table</th>
                        <th>Status</th>
                        <th>Grand Total</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($orders as $o): ?>
                    <?php 
                        // Color-coded waiting warning logic
                        $minutes_open = round((time() - strtotime($o['created_at'])) / 60);
                        $warning_color = ($o['status'] === 'open' && $minutes_open > 45) ? 'color:#c62828; font-weight:900;' : 'font-weight:500;';
                    ?>
                    <tr>
                        <td><strong>#<?= $o['id'] ?></strong></td>
                        <td><?= date('h:i A', strtotime($o['created_at'])) ?></td>
                        <td style="text-transform:capitalize; <?= $warning_color ?>">
                            <?= $o['order_type'] === 'takeout' ? '🥡 Takeout' : '🍽️ Table ' . htmlspecialchars($o['table_number'] ?? 'N/A') ?>
                            <?php if($o['status'] === 'open' && $minutes_open > 45) echo "<br><small style='color:#c62828;'>Waiting {$minutes_open}m!</small>"; ?>
                        </td>
                        <td><span class="status-badge status-<?= htmlspecialchars($o['status'] ?? 'open') ?>"><?= htmlspecialchars($o['status'] ?? 'OPEN') ?></span></td>
                        <td style="font-weight:bold; color:var(--brand); font-size:1.1rem;">₱<?= number_format((float)$o['grand_total'], 2) ?></td>
                        <td>
                            <div style="display:flex; gap:5px;">
                                <button onclick="viewOrderDetails(<?= $o['id'] ?>)" style="background:#005ce6; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold;">📄 Details</button>
                                
                                <button onclick="reprintOrder(<?= $o['id'] ?>)" style="background:#4b5563; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold;">🖨️ Print</button>
                                
                                <?php if(isset($o['status']) && $o['status'] === 'paid'): ?>
                                    <button onclick="refundOrder(<?= $o['id'] ?>, <?= $o['grand_total'] ?>)" style="background:#c62828; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold;">Refund</button>
                                <?php elseif(isset($o['status']) && $o['status'] === 'open'): ?>
                                    <button onclick="voidOpenOrder(<?= $o['id'] ?>)" style="background:#f59e0b; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:bold;">Void</button>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($orders)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:40px; color:gray; font-size:1.1rem;">No orders found for this date. Go make some sales! 🚀</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // --- AUTO REFRESH LOGIC ---
        let refreshTimer;
        function toggleAutoRefresh() {
            if (document.getElementById('autoRefreshToggle').checked) {
                sessionStorage.setItem('liveMode', 'true');
                refreshTimer = setTimeout(() => window.location.reload(), 60000); // Reload every 60s
            } else {
                sessionStorage.setItem('liveMode', 'false');
                clearTimeout(refreshTimer);
            }
        }
        if (sessionStorage.getItem('liveMode') === 'true') {
            document.getElementById('autoRefreshToggle').checked = true;
            toggleAutoRefresh();
        }

        // --- LIVE SEARCH LOGIC ---
        function filterOrders() {
            let input = document.getElementById("orderSearch").value.toLowerCase();
            let rows = document.querySelectorAll("#orderHistoryTable tbody tr");
            
            rows.forEach(row => {
                if (row.cells.length < 2) return; // Skip the empty state message row
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(input) ? "" : "none";
            });
        }

        // --- EXPORT TO CSV LOGIC ---
        function exportTableToCSV(filename) {
            let csv = [];
            let rows = document.querySelectorAll("#orderHistoryTable tr");
            
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                // Skip the "Actions" column (index 5)
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

        // --- EXISTING FUNCTIONS ---
        async function reprintOrder(orderId) {
            Swal.fire({title: 'Printing...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
            try {
                const res = await fetch(`../api/print_order.php?order_id=${orderId}&type=receipt`);
                const data = await res.json();
                if (data.success) {
                    Swal.fire({icon: 'success', title: 'Printed!', timer: 1000, showConfirmButton: false});
                } else {
                    Swal.fire('Print Failed', data.message || data.error || 'Unknown error', 'error');
                }
            } catch (e) {
                Swal.fire('Error', 'Could not reach printer service.', 'error');
            }
        }

        async function refundOrder(orderId, amount) {
            const { value: formValues } = await Swal.fire({
                title: `Refund Order #${orderId}`,
                html: `
                    <div style="margin-bottom:15px; font-size:0.9rem; color:gray;">Refunding will inject a negative balance into the cash drawer to keep the Z-Report perfectly accurate.</div>
                    <input type="text" id="ref-reason" class="swal2-input" placeholder="Reason (e.g., Cold Food, Mistake)">
                    <input type="password" id="ref-pin" class="swal2-input" placeholder="Manager PIN Required">
                `,
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Authorize Refund', confirmButtonColor: '#d33',
                preConfirm: () => {
                    const reason = document.getElementById('ref-reason').value;
                    const pin = document.getElementById('ref-pin').value;
                    if (!reason || !pin) { Swal.showValidationMessage('Both Reason and Manager PIN are required!'); return false; }
                    return { reason, pin };
                }
            });

            if (formValues) {
                const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                try {
                    const res = await fetch('../api/refund_order.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                        body: JSON.stringify({ order_id: orderId, pin: formValues.pin, reason: formValues.reason })
                    });
                    const data = await res.json();
                    if (data.success) {
                        Swal.fire({icon: 'success', title: 'Refund Processed', text: 'Z-Report has been automatically updated.', timer: 2000, showConfirmButton: false})
                        .then(() => window.location.reload());
                    } else {
                        Swal.fire('Declined', data.error, 'error');
                    }
                } catch(e) { Swal.fire('Error', 'Connection failed.', 'error'); }
            }
        }

        async function voidOpenOrder(orderId) {
            Swal.fire({
                title: `Void Order #${orderId}?`,
                text: "This order is open and unpaid. Are you sure you want to delete it permanently?",
                icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Void It', confirmButtonColor: '#f59e0b'
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    try {
                        const res = await fetch('../api/clear_order.php', { // Assuming clear_order is used for this
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ order_id: orderId })
                        });
                        const data = await res.json();
                        if (data.success) {
                            Swal.fire({icon: 'success', title: 'Order Voided', timer: 1000, showConfirmButton: false})
                            .then(() => window.location.reload());
                        } else {
                            Swal.fire('Error', data.error || 'Could not void order.', 'error');
                        }
                    } catch(e) { Swal.fire('Error', 'Connection failed.', 'error'); }
                }
            });
        }
        
        async function viewOrderDetails(orderId) {
            try {
                Swal.fire({title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
                
                const res = await fetch(`../api/get_order_details.php?order_id=${orderId}`);
                const data = await res.json();
                
                if (!data.success) {
                    return Swal.fire('Error', data.error, 'error');
                }
                
                const o = data.order;
                let html = `<div style="text-align:left; font-family:monospace; background:#f9fafb; padding:15px; border:1px solid #ddd; border-radius:8px; max-height:450px; overflow-y:auto; font-size:0.95rem; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);">`;
                
                html += `<div style="text-align:center; font-weight:bold; font-size:1.2rem; margin-bottom:10px; color:var(--brand-dark);">ORDER #${o.id}</div>`;
                html += `<div><strong>Opened:</strong> ${new Date(o.created_at).toLocaleString()}</div>`;
                if (o.paid_at) { html += `<div><strong>Closed:</strong> ${new Date(o.paid_at).toLocaleString()}</div>`; }
                
                html += `<div><strong>Type:</strong> ${o.order_type.toUpperCase()} ${o.table_number ? '(Table '+o.table_number+')' : ''}</div>`;
                if (o.customer_name) html += `<div><strong>Customer:</strong> ${o.customer_name}</div>`;
                if (o.cashier) html += `<div><strong>Cashier:</strong> ${o.cashier}</div>`;
                html += `<div><strong>Status:</strong> <span style="text-transform:uppercase; font-weight:bold; color:${o.status === 'paid' ? 'green' : (o.status === 'refunded' ? 'red' : (o.status === 'voided' ? '#c62828' : 'orange'))}">${o.status}</span></div>`;
                
                if (o.void_reason) {
                    html += `<div style="margin-top: 5px; padding: 5px; background: #ffebee; border-left: 3px solid #c62828;"><strong>Reason:</strong> <span style="color:#c62828; font-weight:bold;">${o.void_reason}</span></div>`;
                }

                html += `<hr style="border-top:1px dashed #ccc; margin:15px 0;">`;
                
                data.items.forEach(i => {
                    let name = i.variation_name ? `${i.product_name} (${i.variation_name})` : i.product_name;
                    html += `<div style="display:flex; justify-content:space-between; margin-bottom:2px;"><span><strong>${i.quantity}x</strong> ${name}</span> <span>₱${parseFloat(i.line_total).toFixed(2)}</span></div>`;
                    
                    i.modifiers.forEach(m => {
                        html += `<div style="color:gray; font-size:0.85rem; padding-left:20px;">+ ${m.name} <small>(₱${parseFloat(m.price).toFixed(2)})</small></div>`;
                    });
                    
                    if (parseFloat(i.discount_amount) > 0) {
                        html += `<div style="color:#c62828; font-size:0.85rem; padding-left:20px;">- Discount: ₱${parseFloat(i.discount_amount).toFixed(2)} ${i.discount_note ? `(${i.discount_note})` : ''}</div>`;
                    }
                    html += `<div style="margin-bottom:8px;"></div>`;
                });
                
                html += `<hr style="border-top:1px dashed #ccc; margin:10px 0;">`;
                
                html += `<div style="display:flex; justify-content:space-between;"><span>Subtotal:</span> <span>₱${parseFloat(o.subtotal).toFixed(2)}</span></div>`;
                
                if (parseFloat(o.discount_total) > 0) {
                    html += `<div style="display:flex; justify-content:space-between; color:#c62828;"><span>Total Discount:</span> <span>-₱${parseFloat(o.discount_total).toFixed(2)}</span></div>`;
                    
                    let finalNote = '';
                    if (o.sc_records && o.sc_records.length > 0) {
                        finalNote = `SC/PWD (${o.sc_records.length} Pax)`;
                    } else if (o.discount_name) {
                        finalNote = o.discount_name + (o.discount_type === 'percent' ? ` (${parseFloat(o.discount_value)}%)` : '');
                    } else if (o.discount_note) {
                        finalNote = o.discount_note;
                    }
                    
                    if (finalNote) {
                        html += `<div style="color:#c62828; font-size:0.8rem; font-style:italic;">Note: ${finalNote}</div>`;
                    }
                }
                
                html += `<div style="display:flex; justify-content:space-between; font-weight:bold; font-size:1.2rem; margin-top:10px; color:var(--brand);"><span>Grand Total:</span> <span>₱${parseFloat(o.grand_total).toFixed(2)}</span></div>`;
                
                // Staff Tip Display Fix incorporated here:
                if (parseFloat(o.tip_amount) > 0) {
                    html += `<div style="display:flex; justify-content:space-between; font-weight:bold; font-size:1rem; margin-top:5px; color:#2e7d32;"><span>Staff Tip:</span> <span>₱${parseFloat(o.tip_amount).toFixed(2)}</span></div>`;
                }
                
                if (data.payments && data.payments.length > 0) {
                    html += `<hr style="border-top:1px dashed #ccc; margin:15px 0;">`;
                    html += `<div style="margin-bottom:5px;"><strong>Tender Details:</strong></div>`;
                    data.payments.forEach(p => {
                        let isRefund = parseFloat(p.amount) < 0;
                        html += `<div style="display:flex; justify-content:space-between; ${isRefund ? 'color:#c62828;' : ''}"><span>${p.method.toUpperCase()} ${isRefund ? 'Refunded' : 'Tendered'}:</span> <span>₱${parseFloat(Math.abs(p.amount)).toFixed(2)}</span></div>`;
                        if (parseFloat(p.change_given) > 0) {
                            html += `<div style="display:flex; justify-content:space-between; color:green;"><span>Change Given:</span> <span>₱${parseFloat(p.change_given).toFixed(2)}</span></div>`;
                        }
                    });
                }

                html += `</div>`;
                
                Swal.fire({
                    title: false,
                    html: html,
                    width: 450,
                    showDenyButton: true,
                    confirmButtonColor: '#6B4226',
                    denyButtonColor: '#4b5563',
                    confirmButtonText: 'Close',
                    denyButtonText: '🖨️ Print Receipt',
                    showClass: { popup: 'animate__animated animate__fadeInUp animate__faster' }
                }).then((result) => {
                    if (result.isDenied) {
                        reprintOrder(orderId);
                    }
                });
                
            } catch(e) { 
                Swal.fire('Error', 'Could not load details.', 'error'); 
            }
        }
    </script>
</body>
</html>