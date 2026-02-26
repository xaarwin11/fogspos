<?php
require_once '../db.php';
session_start();
if (empty($_SESSION['user_id'])) { header("Location: ../"); exit; }
// Generate token if it doesn't exist
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>POS - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <script src="../assets/js/sweetalert2.js"></script>
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    <style>
        .category-scroll { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 10px; margin-bottom: 10px; scrollbar-width: none; }
        .category-scroll::-webkit-scrollbar { display: none; }
        .search-bar { width: 100%; padding: 12px 15px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 15px; font-size: 1rem; background: #fff; }
        
        .bill-item { padding: 12px 0; border-bottom: 1px dashed var(--border); animation: slideIn 0.2s ease-out; }
        .bill-mods { font-size: 0.8rem; color: var(--brand-light); padding-left: 10px; font-style: italic; }
        
        .qty-controls { display: flex; align-items: center; gap: 10px; background: #f3f4f6; padding: 4px 8px; border-radius: 20px; }
        .qty-btn { width: 28px; height: 28px; border-radius: 50%; border: 1px solid #ddd; background: white; cursor: pointer; font-weight: bold; }
        .remove-link { color: var(--danger); font-size: 0.75rem; font-weight: 700; cursor: pointer; margin-left: 10px; text-transform: uppercase; }

        .type-toggle { display: flex; background: #e5e7eb; padding: 4px; border-radius: 10px; margin-bottom: 15px; }
        .type-btn { flex: 1; border: none; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: 600; color: var(--text-muted); background: transparent; transition: 0.2s; }
        .type-btn.active { background: white; color: var(--brand); box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        
        @keyframes slideIn { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: translateX(0); } }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="pos-container">
        <div class="products-section" style="display:flex; flex-direction:column; overflow:hidden;">
            <input type="text" id="search" class="search-bar" placeholder="🔍 Search menu..." autocomplete="off">
            <div class="category-scroll" id="catContainer"></div>
            <div class="products-grid" id="prodContainer">
                <div style="grid-column:1/-1; text-align:center; color:#999; margin-top:50px;">Loading Menu...</div>
            </div>
        </div>

        <div class="cart-section">
            <button class="mobile-close-cart" onclick="document.querySelector('.cart-section').classList.remove('mobile-active')">
                ← Back to Menu
            </button>

            <div style="padding: 15px; border-bottom: 1px solid var(--border); background: #f9fafb;">
                <div class="type-toggle">
                    <button class="type-btn active" id="btnDineIn" onclick="setOrderMode('dine_in')">🍽️ Dine In</button>
                    <button class="type-btn" id="btnTakeout" onclick="setOrderMode('takeout')">🥡 Takeout</button>
                </div>
                
                <div id="tableSelector" style="padding:12px; border:1px solid var(--border); border-radius:10px; background:white; cursor:pointer; display:flex; justify-content:space-between; margin-bottom:5px;" onclick="showTablePopup()">
                    <span id="tableName" style="font-weight:700;">Select Table</span>
                    <span style="color:var(--brand);">Change ❯</span>
                </div>
                
                <button id="btnTransfer" class="btn secondary" style="width:100%; display:none; padding:8px; font-size:0.9rem;" onclick="transferTablePopup()">🔄 Move Order to Another Table</button>
            </div>

            <div class="cart-items" id="cartContainer" style="padding: 0 15px; flex:1; overflow-y:auto;">
                <div style="text-align:center; padding:40px; color:#ccc;">Cart is empty</div>
            </div>

            <div style="padding:20px; background:var(--bg-dark); border-top:1px solid var(--border);">
                <div id="summaryArea" style="display:none;">
                    <div class="math-row" style="display:flex; justify-content:space-between; font-size:0.9rem; color:var(--text-muted);">
                        <span>Subtotal</span>
                        <span id="txtSubtotal">₱0.00</span>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; font-size:1.5rem; font-weight:800; margin-top:10px; color:var(--text-main);">
                    <span>Total</span>
                    <span id="txtGrandTotal" style="color:var(--brand);">₱0.00</span>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:15px;">
                    <button class="btn secondary" onclick="printOrder('kitchen', event)">🖨️ Send Items</button>
                    <button class="btn secondary" onclick="printOrder('bill', event)">📄 Print Bill</button>
                    
                    <button class="btn secondary" onclick="applyDiscountPopup()">🏷️ Disc</button>
                    <button class="btn secondary" onclick="clearCart()">🗑️ Clear</button>
                    <button class="btn" onclick="saveOrder()" style="grid-column: span 2;">Save Order</button>
                    <button class="btn success" style="grid-column: span 2; padding:18px; font-size:1.1rem;" onclick="checkout()">CHARGE</button>
                </div>
            </div>
        </div>
    </div>
    
    <button class="mobile-cart-toggle" onclick="document.querySelector('.cart-section').classList.add('mobile-active')">
        🛒 View Cart & Pay
    </button>
    
    <script src="../assets/js/pos.js?v=<?php echo time(); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            initPOS();
            document.getElementById('search').addEventListener('input', renderProducts);
        });

        async function setOrderMode(mode) {
            state.mode = mode;
            document.getElementById('btnDineIn').classList.toggle('active', mode === 'dine_in');
            document.getElementById('btnTakeout').classList.toggle('active', mode === 'takeout');
            
            // 🚨 FIX: Completely wipe all table and order memory when switching modes
            state.activeTableId = null;
            state.activeOrderId = null;
            
            state.cart = []; state.discount_id = null; state.discount_note = ''; state.senior_details = [];
            state.custom_discount = { is_active: false };
            
            if (mode === 'takeout') {
                document.getElementById('tableName').innerText = 'Select Takeout';
                showTakeoutPopup();
            } else {
                document.getElementById('tableName').innerText = 'Select Table';
            }
            renderCart();
        }

        async function showTablePopup() {
            if(state.mode === 'takeout') return showTakeoutPopup();
            const r = await fetch('../api/get_tables.php');
            const tables = await r.json();
            let html = '<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px;">';
            tables.forEach(t => {
                const sc = t.status === 'occupied' ? '#fee2e2' : '#f0fdf4';
                html += `<div style="padding:15px 5px; border-radius:8px; cursor:pointer; font-weight:bold; background:${sc}" onclick="pickTable(${t.id}, '${t.table_number}', '${t.status}')">${t.table_number}</div>`;
            });
            Swal.fire({ title: 'Select Table', html: html + '</div>', showConfirmButton: false });
        }

        async function showTakeoutPopup() {
            const r = await fetch('../api/get_takeouts.php');
            const orders = await r.json();
            let html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; max-height:300px; overflow-y:auto;">';
            html += `<div style="padding:15px; background:var(--brand); color:white; border-radius:8px; cursor:pointer;" onclick="newTakeout()">+ New Order</div>`;
            orders.forEach(o => {
                html += `<div style="padding:15px; background:#f3f4f6; border-radius:8px; cursor:pointer;" onclick="loadTakeout(${o.id})">Order #${o.id}<br><small>₱${o.grand_total}</small><br><small style="color:gray">${o.time}</small></div>`;
            });
            Swal.fire({ title: 'Takeout Orders', html: html + '</div>', showConfirmButton: false });
        }

        async function newTakeout() {
            const { value: customerName } = await Swal.fire({
                title: 'Takeout Name',
                input: 'text',
                inputPlaceholder: 'Enter customer name (optional)',
                showCancelButton: true,
                confirmButtonColor: '#6B4226'
            });
            
            if (customerName !== undefined) {
                state.activeOrderId = 'new';
                state.customer_name = customerName || 'Guest';
                document.getElementById('tableName').innerText = 'Takeout: ' + state.customer_name;
                Swal.close();
                renderCart();
            }
        }
        // --- 30 SECOND INACTIVITY AUTO-LOGOUT ---
        let idleTimer;
        
        function resetIdleTimer() {
            clearTimeout(idleTimer);
            // 30000 milliseconds = 30 seconds. (Change to 60000 for 1 minute if 30s is too fast)
            idleTimer = setTimeout(() => {
                window.location.href = '../api/auth_logout.php';
            }, 30000); 
        }

        // Listen for ANY screen touch or tap to keep the session alive
        window.onload = resetIdleTimer;
        document.onmousemove = resetIdleTimer;
        document.ontouchstart = resetIdleTimer;
        document.onclick = resetIdleTimer;
        document.onkeypress = resetIdleTimer;
        document.onscroll = resetIdleTimer;
    </script>
</body>
</html>