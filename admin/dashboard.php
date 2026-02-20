<?php
require_once '../db.php';
session_start();

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    header("Location: ../pos/index.php"); 
    exit; 
}

$mysqli = get_db_conn();

// 1. Date Navigator Logic
$date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
$display_date = date('D, M d, Y', strtotime($date));
$is_today = ($date === date('Y-m-d'));

// 2. Fetch Gross Sales & Order Count
$sales_query = "SELECT COUNT(*) as total_orders, COALESCE(SUM(grand_total), 0) as total_sales FROM orders WHERE DATE(created_at) = '$date' AND status = 'paid'";
$sales = $mysqli->query($sales_query)->fetch_assoc();

// 3. Fetch Tender Breakdown
$pay_query = "SELECT method, SUM(amount - change_given) as net_amount FROM payments WHERE DATE(created_at) = '$date' GROUP BY method";
$payments = $mysqli->query($pay_query)->fetch_all(MYSQLI_ASSOC);

// 4. Fetch Order History Log
$orders_query = "SELECT o.*, t.table_number FROM orders o LEFT JOIN tables t ON o.table_id = t.id WHERE DATE(o.created_at) = '$date' ORDER BY o.created_at DESC";
$orders = $mysqli->query($orders_query)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Dashboard - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .dashboard-layout { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); }
        .stat-card h3 { margin: 0 0 10px 0; color: var(--text-muted); font-size: 1rem; text-transform: uppercase; letter-spacing: 1px; }
        .stat-card .value { font-size: 2.8rem; font-weight: 800; color: var(--brand); margin: 0; }
        
        .orders-table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); }
        .orders-table th, .orders-table td { padding: 15px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .orders-table th { background: var(--bg-dark); color: var(--brand-dark); font-weight: 700; text-transform: uppercase; font-size: 0.85rem; }
        .orders-table tr:hover { background: #fdfaf6; }
        
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; }
        .status-paid { background: #e8f5e9; color: #2e7d32; }
        .status-open { background: #fff3e0; color: #e65100; }
        .status-voided { background: #ffebee; color: #c62828; }
        
        /* Custom Date Picker Widget UI */
        .date-navigator {
            display: flex; align-items: center; background: white; 
            border: 1px solid var(--border); border-radius: 10px; 
            box-shadow: var(--shadow); overflow: hidden;
        }
        .date-nav-btn {
            padding: 10px 15px; text-decoration: none; color: var(--brand); 
            background: #f9fafb; font-weight: bold; font-size: 1.1rem;
            transition: 0.2s;
        }
        .date-nav-btn:hover { background: #e5e7eb; }
        .date-display {
            padding: 10px 20px; font-weight: bold; color: var(--text-main);
            position: relative; cursor: pointer; display: flex; align-items: center; gap: 8px;
        }
        .date-display:hover { background: #fafafa; }
        /* This keeps the native picker clickable but makes it invisible over our beautiful text */
        .hidden-date-input {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; cursor: pointer;
        }
    </style>
</head>
<body>
    <?php include '../components/navbar.php'; ?>

    <div class="dashboard-layout">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
            <h1 style="color:var(--brand-dark); margin:0;">Sales Dashboard</h1>
            
            <div class="date-navigator">
                <a href="?date=<?= $prev_date ?>" class="date-nav-btn" style="border-right:1px solid var(--border);">❮</a>
                
                <form method="GET" style="margin:0; height:100%;">
                    <div class="date-display">
                        📅 <?= $is_today ? 'Today' : $display_date ?>
                        <input type="date" name="date" class="hidden-date-input" value="<?= $date ?>" onchange="this.form.submit()">
                    </div>
                </form>

                <a href="?date=<?= $next_date ?>" class="date-nav-btn" style="border-left:1px solid var(--border);">❯</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Gross Sales</h3>
                <div class="value">₱<?= number_format($sales['total_sales'], 2) ?></div>
                <div style="font-size:0.9rem; color:gray; margin-top:5px;">For <?= date('F d, Y', strtotime($date)) ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Completed Orders</h3>
                <div class="value"><?= $sales['total_orders'] ?></div>
                <div style="font-size:0.9rem; color:gray; margin-top:5px;">Successfully paid and closed</div>
            </div>
            
            <div class="stat-card">
                <h3>Tender Breakdown</h3>
                <div style="margin-top:10px;">
                    <?php foreach($payments as $p): ?>
                        <div style="display:flex; justify-content:space-between; margin-bottom:8px; border-bottom:1px dashed #eee; padding-bottom:5px;">
                            <span style="text-transform:capitalize; color:var(--text-muted); font-weight:600;">
                                <?= $p['method'] === 'cash' ? '💵' : ($p['method'] === 'gcash' ? '📱' : '💳') ?> <?= $p['method'] ?>
                            </span>
                            <span style="font-weight:bold; font-size:1.1rem; color:var(--text-main);">₱<?= number_format($p['net_amount'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if(empty($payments)) echo "<span style='color:gray; font-style:italic;'>No payments recorded yet.</span>"; ?>
                </div>
            </div>
        </div>

        <h2 style="color:var(--brand-dark); margin-bottom:15px;">Order History</h2>
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Time</th>
                    <th>Type / Table</th>
                    <th>Status</th>
                    <th>Discount</th>
                    <th>Grand Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $o): ?>
                <tr>
                    <td><strong>#<?= $o['id'] ?></strong></td>
                    <td><?= date('h:i A', strtotime($o['created_at'])) ?></td>
                    <td style="text-transform:capitalize; font-weight:500;">
                        <?= $o['order_type'] === 'takeout' ? '🥡 Takeout' : '🍽️ Table ' . $o['table_number'] ?>
                    </td>
                    <td><span class="status-badge status-<?= $o['status'] ?>"><?= $o['status'] ?></span></td>
                    <td style="color:var(--danger); font-size:0.9rem;">
                        <?= $o['discount_total'] > 0 ? "-₱" . number_format($o['discount_total'], 2) : '-' ?>
                    </td>
                    <td style="font-weight:bold; color:var(--brand); font-size:1.1rem;">₱<?= number_format($o['grand_total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if(empty($orders)): ?>
                <tr><td colspan="6" style="text-align:center; padding:40px; color:gray; font-size:1.1rem;">No orders found for this date. Go make some sales! 🚀</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>