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
    <?php include '../pwa.php'; ?>
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
            <div style="display:flex; gap:10px; margin-bottom: 15px;">
                <input type="text" id="search" class="search-bar" style="margin-bottom:0;" placeholder="🔍 Search menu..." autocomplete="off">
                <button class="btn secondary" style="white-space:nowrap; font-weight:bold; padding:0 20px; border: 2px dashed #ccc;" onclick="addOpenItem()">+ Off-Menu Item</button>
            </div>
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

            <div class="cart-bottom-panel" id="cartBottomPanel">
                
                <div style="padding: 15px 20px;">
                    <div id="summaryArea" style="display:none;">
                        <div class="math-row" style="display:flex; justify-content:space-between; font-size:0.9rem; color:var(--text-muted);">
                            <span>Subtotal</span>
                            <span id="txtSubtotal">₱0.00</span>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items: center; margin-top:5px;">
                        <span style="font-size:1.5rem; font-weight:800; color:var(--text-main);">Total</span>
                        <span id="txtGrandTotal" style="font-size:1.5rem; font-weight:800; color:var(--brand);">₱0.00</span>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 60px; gap:10px; margin-top:12px;">
                        <button class="btn" style="padding:15px; font-size:1.2rem; font-weight:800;" onclick="saveOrder()">💾 SAVE ORDER</button>
                        <button class="btn secondary" style="font-size:1.3rem; display:flex; justify-content:center; align-items:center;" onclick="toggleCartDrawer()" id="drawerBtn">⚙️</button>
                    </div>
                </div>

                <div class="cart-actions-drawer">
                    <div class="action-grid">
                        <button class="btn secondary" onclick="printOrder('kitchen', event)">🖨️ Send</button>
                        <button class="btn secondary" onclick="printOrder('bill', event)">📄 Bill</button>
                        <button class="btn secondary" onclick="applyDiscountPopup()">🏷️ Disc</button>
                        
                        <button class="btn secondary" onclick="splitOrderPopup()">✂️ Split</button>
                        
                        <button class="btn secondary" onclick="clearCart()">🗑️ Clear</button>
                        <button class="btn success" onclick="checkout()">💵 Charge</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <button class="mobile-cart-toggle" onclick="document.querySelector('.cart-section').classList.add('mobile-active')">
        🛒 View Cart & Pay
    </button>
    
    <script src="../assets/js/pos.js?v=<?= filemtime('../assets/js/pos.js') ?>"></script>
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
            
            let html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; max-height:400px; overflow-y:auto; padding:10px 5px;">';
            
            // The New Walk-in Button
            html += `<div style="padding:15px; background:var(--brand); color:white; border-radius:10px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:16px; box-shadow:0 4px 6px rgba(0,0,0,0.1);" onclick="newTakeout()">+ New Walk-in</div>`;
            
            orders.forEach(o => {
                let isWeb = o.reference && o.reference.startsWith('WEB-');
                let displayName = o.customer_name ? o.customer_name : `Order #${o.id}`;
                
                // Color code the kitchen status
                let badgeColor = o.status === 'open' ? '#f59e0b' : (o.status === 'preparing' ? '#3b82f6' : '#10b981');
                
                html += `
                <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; text-align:left; position:relative; transition:0.2s; box-shadow:0 2px 4px rgba(0,0,0,0.05);" onclick="loadTakeout(${o.id})">
                    
                    ${isWeb ? `<div style="position:absolute; top:-8px; right:-8px; background:#dc2626; color:white; font-size:10px; font-weight:900; padding:4px 8px; border-radius:12px; box-shadow:0 2px 4px rgba(0,0,0,0.2);">WEB</div>` : ''}
                    
                    <div style="font-weight:900; color:#1e293b; font-size:15px; margin-bottom:6px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-transform:uppercase;">${displayName}</div>
                    
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <span style="color:var(--brand); font-weight:900; font-size:16px;">₱${parseFloat(o.grand_total).toFixed(2)}</span>
                        <span style="font-size:10px; background:${badgeColor}; color:white; padding:3px 6px; border-radius:6px; font-weight:bold; text-transform:uppercase;">${o.status}</span>
                    </div>
                    
                    <div style="font-size:11px; color:#64748b; font-weight:bold;">🕒 ${o.time}</div>
                </div>`;
            });
            
            Swal.fire({ 
                title: '<h2 style="margin:0; font-weight:900; color:#1e293b;">Active Takeouts</h2>', 
                html: html + '</div>', 
                showConfirmButton: false, 
                background: '#f1f5f9',
                width: 500
            });
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
            }, 60000); 
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