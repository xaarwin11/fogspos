<?php
require_once '../db.php';
session_start();

$is_logged_in = isset($_SESSION['customer_id']);
if (empty($_SESSION['public_csrf'])) { 
    $_SESSION['public_csrf'] = bin2hex(random_bytes(32)); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Order - Fogs Tasas Cafe</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <script src="../assets/js/sweetalert2.js"></script>
    <meta name="csrf-token" content="<?= $_SESSION['public_csrf'] ?>">
    <meta name="logged-in" content="<?= $is_logged_in ? 'true' : 'false' ?>">
    
    <style>
        /* =========================================================
           ✨ PREMIUM CAFE APP UI OVERRIDE ✨
           ========================================================= */
        @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap');

        body { 
            background: #F9F7F4; /* Softer, warmer off-white */
            margin: 0; padding-bottom: 120px; 
            font-family: 'Nunito', -apple-system, sans-serif; /* Rounder, friendlier font */
            color: var(--text-main, #3e2b22);
            -webkit-font-smoothing: antialiased;
        }

        /* 🌆 The Hero Header */
        .app-header { 
            background: linear-gradient(135deg, var(--brand-dark) 0%, var(--brand) 100%);
            padding: 40px 20px 30px 20px; 
            border-radius: 0 0 30px 30px; /* Gorgeous bottom curve */
            box-shadow: 0 10px 30px rgba(107, 66, 38, 0.2);
            position: relative;
            z-index: 100;
            display: flex; justify-content: space-between; align-items: flex-start;
            color: white;
        }
        .header-title { margin: 0; font-size: 1.8rem; font-weight: 900; letter-spacing: -0.5px; }
        .user-greeting { font-size: 1rem; color: rgba(255,255,255,0.8); margin-top: 5px; font-weight: 600; }
        .guest-warning { font-size: 0.9rem; color: #ffeb3b; margin-top: 5px; font-weight: 700; display: flex; align-items: center; gap: 5px; }

        .auth-buttons { display: flex; gap: 8px; flex-direction: column; align-items: flex-end; }
        .auth-buttons button {
            background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.4); 
            padding: 8px 18px; border-radius: 50px; font-weight: 700; font-size: 0.85rem; 
            cursor: pointer; transition: all 0.2s; backdrop-filter: blur(5px);
        }
        .auth-buttons button.solid { background: white; color: var(--brand-dark); border: none; }
        .auth-buttons button:active { transform: scale(0.95); }

        /* 🗂️ Floating Category Pills */
        .category-wrapper {
            margin-top: -20px; /* Pulls it up over the header curve */
            position: sticky; top: 10px; z-index: 99;
        }
        .category-scroll { 
            display: flex; gap: 10px; overflow-x: auto; padding: 5px 20px; 
            -webkit-overflow-scrolling: touch; scrollbar-width: none;
        }
        .category-scroll::-webkit-scrollbar { display: none; }
        .cat-btn { 
            padding: 12px 24px; border-radius: 50px; border: none; 
            background: white; color: var(--brand-light); 
            font-weight: 800; font-size: 0.95rem; white-space: nowrap; 
            cursor: pointer; transition: 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
        }
        .cat-btn.active { 
            background: var(--brand); color: white; 
            transform: translateY(-2px); box-shadow: 0 8px 20px rgba(107, 66, 38, 0.3);
        }

        /* ☕ Premium Product Grid */
        .products-grid { 
            display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); 
            gap: 20px; padding: 25px 20px; 
        }
        .product-card { 
            background: white; border-radius: 24px; padding: 12px; display: flex; flex-direction: column; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.04); transition: transform 0.2s; border: 1px solid rgba(0,0,0,0.02);
        }
        .product-card:active { transform: scale(0.96); }
        
        /* Pastel Art Placeholders */
        .img-placeholder { 
            height: 130px; border-radius: 18px; margin-bottom: 15px; 
            display: flex; align-items: center; justify-content: center; font-size: 3rem; 
            background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%);
            box-shadow: inset 0 -4px 10px rgba(0,0,0,0.02);
        }
        /* Dynamic Placeholder Colors */
        .bg-coffee { background: linear-gradient(135deg, #f3e7e0 0%, #e3d0c3 100%); }
        .bg-food { background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); }
        .bg-pastry { background: linear-gradient(135deg, #fce4ec 0%, #f8bbd0 100%); }

        .product-name { font-weight: 800; font-size: 1.05rem; margin-bottom: 5px; line-height: 1.2; color: #2c1e16; }
        .product-price { color: var(--brand); font-weight: 900; font-size: 1.15rem; margin-bottom: 15px; }
        
        .add-btn { 
            background: var(--brand-light); color: white; border: none; 
            padding: 12px; border-radius: 16px; font-weight: 800; font-size: 0.95rem; 
            cursor: pointer; width: 100%; margin-top: auto; 
            box-shadow: 0 4px 10px rgba(141, 110, 99, 0.2); transition: 0.2s;
        }
        .add-btn:active { background: var(--brand-dark); }

        /* 🛒 The Floating Action Cart */
        .floating-cart { 
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(150px); 
            width: calc(100% - 40px); max-width: 450px; 
            background: var(--brand-dark); color: white; 
            padding: 18px 25px; border-radius: 20px; display: flex; justify-content: space-between; 
            align-items: center; cursor: pointer; opacity: 0; transition: 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); z-index: 1000;
            box-shadow: 0 15px 35px rgba(85, 50, 31, 0.4);
            border: 1px solid rgba(255,255,255,0.1);
        }
        .floating-cart.visible { transform: translateX(-50%) translateY(0); opacity: 1; }
        .cart-left { display: flex; align-items: center; gap: 12px; font-weight: 800; font-size: 1.1rem; }
        .cart-badge { 
            background: #ffeb3b; color: var(--brand-dark); border-radius: 50%; 
            width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; 
            font-size: 0.9rem; font-weight: 900;
        }

        /* 📱 Bottom-Sheet Style Modals (SweetAlert Customization) */
        .swal2-container.swal2-bottom { align-items: flex-end; }
        .swal2-popup { 
            border-radius: 30px 30px 0 0 !important; /* Bottom sheet look */
            padding: 30px 20px !important; 
            margin: 0 !important; width: 100% !important; max-width: 500px !important;
            animation: slideUp 0.3s ease-out !important;
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        
        .var-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 15px; }
        .var-btn { 
            border: 2px solid #eee; background: white; padding: 18px 10px; 
            border-radius: 18px; font-weight: 800; cursor: pointer; 
            color: #555; text-align: center; transition: 0.2s;
            box-shadow: 0 4px 10px rgba(0,0,0,0.02);
        }
        .var-btn.active { border-color: var(--brand); background: #fff8f5; color: var(--brand); }
        .var-btn .price { display: block; font-size: 0.85rem; color: var(--tan); margin-top: 5px; font-weight: 900; }
        
        .swal2-confirm { border-radius: 50px !important; padding: 15px 30px !important; font-weight: 900 !important; font-size: 1.1rem !important; width: 100%; }
        .swal2-cancel { border-radius: 50px !important; padding: 15px 30px !important; font-weight: 800 !important; }
        .swal2-input { border-radius: 15px !important; border: 2px solid #eee !important; padding: 15px !important; font-size: 1rem !important; background: #fafafa !important; }
        .swal2-input:focus { border-color: var(--brand) !important; box-shadow: none !important; background: white !important; }

        /* SC/PWD Form Enhancements */
        .sc-form label { font-weight: 800; color: #555; font-size: 0.9rem; margin-top: 10px; display: block; }
        .sc-form select, .sc-form input[type="text"] { width: 100%; padding: 15px; border: 2px solid #eee; border-radius: 15px; margin-top: 5px; font-weight: bold; background: #fafafa; }
        .sc-form input[type="file"] { margin-top: 10px; padding: 15px; background: #f0f0f0; border-radius: 15px; width: 100%; }
    </style>
</head>
<body>

    <header class="app-header">
        <div>
            <h1 class="header-title">Fogs Tasas</h1>
            <?php if ($is_logged_in): ?>
                <div class="user-greeting">Welcome back, <?= htmlspecialchars($_SESSION['customer_name']) ?> 👋</div>
            <?php else: ?>
                <div class="guest-warning">Sign in to earn rewards</div>
            <?php endif; ?>
        </div>
        <div class="auth-buttons">
            <?php if (!$is_logged_in): ?>
                <button class="solid" onclick="openAuth('login')">Sign In</button>
                <button onclick="openAuth('register')">Register</button>
            <?php endif; ?>
        </div>
    </header>

    <div class="category-wrapper">
        <nav class="category-scroll" id="catContainer"></nav>
    </div>
    
    <main class="products-grid" id="prodContainer"></main>

    <div id="cartBtn" class="floating-cart" onclick="reviewCart()">
        <div class="cart-left"><span class="cart-badge" id="cartCount">0</span> <span>View Cart</span></div>
        <div style="font-weight:900; font-size:1.2rem;" id="cartTotal">₱0.00</div>
    </div>

    <script>
        let state = { products: [], categories: [], modifiers: [], cart: [], currentCategory: 'All', sc_pwd_details: null };
        const isLoggedIn = document.querySelector('meta[name="logged-in"]').content === 'true';
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        async function initApp() {
            const res = await fetch('../api/get_products.php');
            const data = await res.json();
            if (data.success) {
                state.products = data.products; state.categories = data.categories; state.modifiers = data.modifiers;
                renderCategories(); renderProducts();
            }
        }

        function renderCategories() {
            let html = `<button class="cat-btn active" onclick="filterCat('All')">All Menu</button>`;
            state.categories.forEach(c => html += `<button class="cat-btn" onclick="filterCat('${c.name}')">${c.name}</button>`);
            document.getElementById('catContainer').innerHTML = html;
        }

        function filterCat(name) {
            state.currentCategory = name;
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.toggle('active', b.innerText === name));
            renderProducts();
        }

        function renderProducts() {
            const con = document.getElementById('prodContainer'); con.innerHTML = '';
            const filtered = state.products.filter(p => state.currentCategory === 'All' || p.category_name === state.currentCategory);
            
            filtered.forEach(p => {
                // Smart icon and background matching based on category name
                let icon = '☕'; let bgClass = 'bg-coffee';
                const catLower = (p.category_name || '').toLowerCase();
                if(catLower.includes('food') || catLower.includes('meal')) { icon = '🥪'; bgClass = 'bg-food'; }
                if(catLower.includes('pastry') || catLower.includes('cake')) { icon = '🥐'; bgClass = 'bg-pastry'; }
                if(catLower.includes('cold') || catLower.includes('iced')) { icon = '🧊'; bgClass = 'bg-coffee'; }

                const safeJson = JSON.stringify(p).replace(/"/g, '&quot;');
                con.innerHTML += `
                    <div class="product-card">
                        <div class="img-placeholder ${bgClass}">${icon}</div>
                        <div class="product-name">${p.name}</div>
                        <div class="product-price">₱${parseFloat(p.price).toFixed(2)}</div>
                        <button class="add-btn" onclick="handleSelection(${safeJson})">Add to order</button>
                    </div>`;
            });
        }

        async function handleSelection(p) {
            let item = { id: p.id, name: p.name, price: parseFloat(p.price), qty: 1, variation_id: null, variation_name: null, modifiers: [] };

            // Bottom-Sheet Size Selection
            if (p.variations && p.variations.length > 0) {
                const { value: vId } = await Swal.fire({
                    title: 'Select Size', position: 'bottom', showClass: { popup: '' }, hideClass: { popup: '' },
                    html: `
                        <div class="var-grid">
                            ${p.variations.map(v => `<div class="var-btn size-btn" data-id="${v.id}" onclick="document.querySelectorAll('.size-btn').forEach(b=>b.classList.remove('active')); this.classList.add('active'); document.getElementById('swal-v').value=this.dataset.id;">${v.name}<span class="price">₱${parseFloat(v.price).toFixed(2)}</span></div>`).join('')}
                        </div>
                        <input type="hidden" id="swal-v">
                    `,
                    preConfirm: () => document.getElementById('swal-v').value || Swal.showValidationMessage('Please select a size'),
                    confirmButtonColor: '#6B4226', showCancelButton: true
                });
                if (!vId) return;
                const v = p.variations.find(v => v.id == vId);
                item.variation_id = v.id; item.variation_name = v.name; item.name = `${p.name} (${v.name})`; item.price = parseFloat(v.price);
            }

            // Bottom-Sheet Add-on Selection
            const pMods = p.modifiers || [];
            if (pMods.length > 0) {
                const allowed = state.modifiers.filter(m => pMods.includes(Number(m.id)) || pMods.includes(String(m.id)));
                if (allowed.length > 0) {
                    const { value: selectedMods } = await Swal.fire({
                        title: 'Add Extras?', position: 'bottom', showClass: { popup: '' }, hideClass: { popup: '' },
                        html: `
                            <div class="var-grid" style="max-height:300px; overflow-y:auto; padding-bottom: 20px;">
                                ${allowed.map(m => `<div class="var-btn mod-btn" data-id="${m.id}" data-name="${m.name}" data-price="${m.price}" onclick="this.classList.toggle('active')">${m.name}<span class="price">+₱${parseFloat(m.price).toFixed(2)}</span></div>`).join('')}
                            </div>
                        `,
                        preConfirm: () => Array.from(document.querySelectorAll('.mod-btn.active')).map(i => ({ id: parseInt(i.dataset.id), name: i.dataset.name, price: parseFloat(i.dataset.price) })),
                        confirmButtonColor: '#6B4226', confirmButtonText: 'Add to Drink', showCancelButton: true, cancelButtonText: 'Skip'
                    });
                    if (selectedMods) item.modifiers = selectedMods;
                }
            }

            const modKey = item.modifiers.map(m => m.id).sort().join(',');
            const uniqueKey = `${item.id}-${item.variation_id}-${modKey}`;
            const existing = state.cart.find(i => `${i.id}-${i.variation_id}-${i.modifiers.map(m=>m.id).sort().join(',')}` === uniqueKey);
            
            if (existing) existing.qty += 1; else state.cart.push(item);
            updateCartUI();
        }

        function updateCartUI() {
            const btn = document.getElementById('cartBtn');
            if (state.cart.length > 0) {
                let total = 0; let count = 0;
                state.cart.forEach(i => { total += (i.price + i.modifiers.reduce((s,m)=>s+m.price,0)) * i.qty; count += i.qty; });
                document.getElementById('cartCount').innerText = count; 
                document.getElementById('cartTotal').innerText = '₱' + total.toFixed(2);
                btn.classList.add('visible');
            } else { btn.classList.remove('visible'); }
        }

        async function openAuth(action) {
            let html = `
                <input id="auth-phone" type="tel" class="swal2-input" placeholder="Phone Number (e.g. 0912...)">
                <input id="auth-pin" type="password" inputmode="numeric" class="swal2-input" placeholder="4-Digit PIN">
            `;
            if (action === 'register') {
                html += `
                    <input id="auth-name" type="text" class="swal2-input" placeholder="Full Name">
                    <input id="auth-address" type="text" class="swal2-input" placeholder="Home Address (Delivery/Records)">
                    <div style="font-size:0.85rem; color:gray; text-align:left; margin-top:15px; background: #f9f9f9; padding: 15px; border-radius: 12px;">
                        <label style="display: flex; gap: 10px; align-items: start; cursor:pointer;">
                            <input type="checkbox" id="dpa-consent" style="margin-top:3px; transform: scale(1.2);"> 
                            <span>I agree to the collection of my data for Fogs Tasas orders in accordance with the Data Privacy Act.</span>
                        </label>
                    </div>
                `;
            }

            const { value: form } = await Swal.fire({
                title: action === 'login' ? 'Welcome Back' : 'Create Account', position: 'bottom', showClass: { popup: '' }, hideClass: { popup: '' },
                html: html,
                confirmButtonText: action === 'login' ? 'Sign In' : 'Register', confirmButtonColor: '#6B4226', showCancelButton: true,
                preConfirm: () => {
                    const phone = document.getElementById('auth-phone').value;
                    const pin = document.getElementById('auth-pin').value;
                    if(!phone || !pin) { Swal.showValidationMessage('Phone and PIN required'); return false; }
                    
                    if (action === 'register') {
                        if(!document.getElementById('dpa-consent').checked) { Swal.showValidationMessage('You must agree to the data privacy policy'); return false; }
                        return { action, phone, pin, name: document.getElementById('auth-name').value, address: document.getElementById('auth-address').value };
                    }
                    return { action, phone, pin };
                }
            });

            if (form) {
                Swal.fire({title:'Processing...', didOpen:()=>Swal.showLoading()});
                const res = await fetch('auth_customer.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(form) });
                const data = await res.json();
                if (data.success) location.reload(); else Swal.fire('Error', data.error, 'error');
            }
        }

        async function applySCPWD() {
            const { value: details } = await Swal.fire({
                title: 'Verify SC/PWD', position: 'bottom', showClass: { popup: '' }, hideClass: { popup: '' },
                html: `
                    <div class="sc-form" style="text-align:left;">
                        <p style="font-size:0.9rem; color:#666; margin-top:0;">Attach your ID now. The barista will verify it when you pick up your order.</p>
                        <label>Discount Type</label>
                        <select id="sc-type"><option value="SC">Senior Citizen</option><option value="PWD">PWD</option></select>
                        <label>Full Name on ID</label>
                        <input type="text" id="sc-name" placeholder="Juan Dela Cruz">
                        <label>ID Number</label>
                        <input type="text" id="sc-idnum" placeholder="1234-5678">
                        <label>Upload Photo of ID</label>
                        <input type="file" id="sc-file" accept="image/*">
                    </div>
                `,
                confirmButtonColor: '#6B4226', confirmButtonText: 'Attach to Order', showCancelButton: true,
                preConfirm: () => {
                    const fileInput = document.getElementById('sc-file');
                    const name = document.getElementById('sc-name').value;
                    const idnum = document.getElementById('sc-idnum').value;
                    if(!name || !idnum || !fileInput.files[0]) { Swal.showValidationMessage('All fields including the photo are required'); return false; }
                    
                    return new Promise((resolve) => {
                        const reader = new FileReader();
                        reader.onload = (e) => resolve({ type: document.getElementById('sc-type').value, name, id_num: idnum, image: e.target.result });
                        reader.readAsDataURL(fileInput.files[0]);
                    });
                }
            });

            if (details) {
                state.sc_pwd_details = details;
                Swal.fire({title: 'ID Attached!', text: 'Discount will be applied at the counter.', icon: 'success', timer: 2000, showConfirmButton: false});
                setTimeout(reviewCart, 2000); 
            }
        }

        async function reviewCart() {
            if (!isLoggedIn) return openAuth('login');

            let html = '<div style="text-align:left; max-height:40vh; overflow-y:auto; padding-right:10px;">';
            let grandTotal = 0;
            state.cart.forEach((item, idx) => {
                const lineTotal = (item.price + item.modifiers.reduce((s,m)=>s+m.price,0)) * item.qty;
                grandTotal += lineTotal;
                html += `
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 0; border-bottom:1px solid #eee;">
                        <div>
                            <div style="font-weight:800; color:#333; font-size:1.05rem;">${item.name}</div>
                            ${item.modifiers.length > 0 ? `<div style="font-size:0.85rem; color:#888; margin-top:3px;">+ ${item.modifiers.map(m=>m.name).join(', ')}</div>` : ''}
                            <div style="font-weight:900; color:var(--brand); margin-top:5px;">₱${lineTotal.toFixed(2)}</div>
                        </div>
                        <div style="background:#f5f5f5; border-radius:12px; padding:8px 15px; font-weight:800; display:flex; gap:15px; align-items:center;">
                            ${item.qty}x <span style="color:var(--danger); cursor:pointer; font-size:1.2rem;" onclick="removeCartItem(${idx})">✕</span>
                        </div>
                    </div>`;
            });
            
            let scBadge = state.sc_pwd_details ? `<div style="background:#e8f5e9; color:#2e7d32; padding:12px; border-radius:15px; font-size:0.9rem; font-weight:800; margin-top:15px; text-align:center; border: 1px dashed #4caf50;">✅ ${state.sc_pwd_details.type} ID Attached for Verification</div>` : '';
            
            html += `</div>
                     ${scBadge}
                     <button onclick="applySCPWD()" style="width:100%; margin-top:20px; background:transparent; border:2px solid #ddd; color:#666; padding:15px; border-radius:18px; font-weight:800; cursor:pointer; font-size:0.95rem;">🎟️ Claim SC/PWD Discount</button>
                     <div style="margin-top:20px; background:#fafafa; border-radius:18px; padding:20px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:1.2rem; font-weight:800; color:#555;">Total to Pay</span>
                        <span style="font-size:1.8rem; font-weight:900; color:var(--brand);">₱${grandTotal.toFixed(2)}</span>
                     </div>`;

            Swal.fire({
                title: 'Review Order', position: 'bottom', showClass: { popup: '' }, hideClass: { popup: '' },
                html: html, showCancelButton: true, 
                confirmButtonText: 'Submit Pick-Up', confirmButtonColor: '#2e7d32'
            }).then((res) => { if (res.isConfirmed) submitOrder(); });
        }

        window.removeCartItem = function(idx) { state.cart.splice(idx, 1); updateCartUI(); if(state.cart.length > 0) reviewCart(); else Swal.close(); };

        async function submitOrder() {
            Swal.fire({title: 'Sending to Kitchen...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});
            const res = await fetch('save_public_order.php', { 
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken }, 
                body: JSON.stringify({ items: state.cart, sc_pwd: state.sc_pwd_details }) 
            });
            const data = await res.json();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Order Received!', text: 'Your order #' + data.order_id + ' is being prepared. Please pay at the counter.', confirmButtonColor: '#6B4226' });
                state.cart = []; state.sc_pwd_details = null; updateCartUI();
            } else Swal.fire('Error', data.error || 'Network error.', 'error');
        }

        initApp();
    </script>
</body>
</html>