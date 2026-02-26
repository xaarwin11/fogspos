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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Order - Fogs Tasas Cafe</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <script src="../assets/js/sweetalert2.js"></script>
    <meta name="csrf-token" content="<?= $_SESSION['public_csrf'] ?>">
    <meta name="logged-in" content="<?= $is_logged_in ? 'true' : 'false' ?>">
    <style>
        body { background: #fdfcfb; margin: 0; padding-bottom: 80px; }
        .header { text-align: center; padding: 20px; background: var(--brand-dark); color: white; }
        .category-scroll { display: flex; gap: 10px; overflow-x: auto; padding: 15px; background: white; border-bottom: 1px solid #eee; }
        .category-scroll::-webkit-scrollbar { display: none; }
        .cat-btn { padding: 8px 15px; border-radius: 20px; border: 1px solid var(--brand); background: white; color: var(--brand); white-space: nowrap; cursor: pointer; }
        .cat-btn.active { background: var(--brand); color: white; }
        
        .products-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; padding: 15px; }
        .product-card { background: white; border: 1px solid #eee; border-radius: 12px; padding: 12px; display: flex; flex-direction: column; }
        .add-btn { background: var(--brand); color: white; border: none; padding: 10px; border-radius: 8px; font-weight: bold; margin-top: auto; cursor: pointer; }
        
        .floating-cart { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 400px; background: var(--brand-dark); color: white; padding: 15px; border-radius: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.3); z-index: 1000; cursor: pointer; display: none; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin:0; font-size:1.5rem;">☕ Fogs Tasas Cafe</h1>
        <?php if ($is_logged_in): ?>
            <div style="color:var(--brand-light); font-weight:bold; margin-top:5px;">Hello, <?= htmlspecialchars($_SESSION['customer_name']) ?>!</div>
        <?php else: ?>
            <div style="color:#ffd700; font-size:0.85rem; margin-top:5px;">In-store Verified Account Required to Checkout</div>
        <?php endif; ?>
    </div>

    <div class="category-scroll" id="catContainer"></div>
    <div class="products-grid" id="prodContainer"><div style="text-align:center; width:100%; grid-column:1/-1;">Loading menu...</div></div>

    <div id="cartBtn" class="floating-cart" onclick="reviewCart()">
        <span style="font-weight:bold; font-size:1.1rem;">🛒 <span id="cartCount">0</span> Items</span>
        <span id="cartTotal" style="font-weight:bold; font-size:1.1rem;">₱0.00</span>
    </div>

    <script>
        // GLOBAL STATE (Mimicking your pos.js architecture)
        let state = { products: [], categories: [], modifiers: [], cart: [], currentCategory: 'All' };
        const isLoggedIn = document.querySelector('meta[name="logged-in"]').content === 'true';
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        async function initApp() {
            try {
                const res = await fetch('../api/get_products.php');
                const data = await res.json();
                if (data.success) {
                    state.products = data.products;
                    state.categories = data.categories;
                    state.modifiers = data.modifiers;
                    renderCategories();
                    renderProducts();
                } else throw new Error("API Error");
            } catch (e) {
                document.getElementById('prodContainer').innerHTML = "Error loading menu.";
            }
        }

        function renderCategories() {
            let html = `<button class="cat-btn active" onclick="filterCat('All')">All</button>`;
            state.categories.forEach(c => html += `<button class="cat-btn" onclick="filterCat('${c.name}')">${c.name}</button>`);
            document.getElementById('catContainer').innerHTML = html;
        }

        function filterCat(name) {
            state.currentCategory = name;
            document.querySelectorAll('.cat-btn').forEach(b => b.classList.toggle('active', b.innerText === name));
            renderProducts();
        }

        function renderProducts() {
            const con = document.getElementById('prodContainer');
            con.innerHTML = '';
            const filtered = state.products.filter(p => state.currentCategory === 'All' || p.category_name === state.currentCategory);
            
            filtered.forEach(p => {
                con.innerHTML += `
                    <div class="product-card">
                        <div style="height:100px; background:#f3f4f6; border-radius:8px; margin-bottom:10px; display:flex; align-items:center; justify-content:center; color:#ccc;">IMG</div>
                        <div style="font-weight:bold; margin-bottom:5px;">${p.name}</div>
                        <div style="color:var(--brand); font-weight:bold; margin-bottom:10px;">₱${parseFloat(p.price).toFixed(2)}</div>
                        <button class="add-btn" onclick='handleSelection(${JSON.stringify(p).replace(/'/g, "&#39;")})'>Add</button>
                    </div>`;
            });
        }

        // --- THE FULL VARIATION/MODIFIER LOGIC FROM YOUR POS ---
        async function handleSelection(p) {
            let item = { id: p.id, name: p.name, price: parseFloat(p.price), qty: 1, variation_id: null, variation_name: null, modifiers: [] };

            // 1. Variations (Sizes)
            if (p.variations && p.variations.length > 0) {
                const { value: vId } = await Swal.fire({
                    title: 'Select Size', input: 'radio',
                    inputOptions: p.variations.reduce((a, v) => ({...a, [v.id]: `${v.name} (₱${v.price})`}), {}),
                    confirmButtonColor: '#6B4226', showCancelButton: true
                });
                if (!vId) return;
                const v = p.variations.find(v => v.id == vId);
                item.variation_id = v.id; item.variation_name = v.name;
                item.name = `${p.name} (${v.name})`; item.price = parseFloat(v.price);
            }

            // 2. Modifiers (Add-ons)
            const pMods = p.modifiers || [];
            if (pMods.length > 0) {
                const allowed = state.modifiers.filter(m => pMods.includes(Number(m.id)) || pMods.includes(String(m.id)));
                if (allowed.length > 0) {
                    const { value: selectedMods } = await Swal.fire({
                        title: 'Add-ons?',
                        html: `<div style="text-align:left;">${allowed.map(m => `
                            <label style="display:flex; justify-content:space-between; padding:10px; border-bottom:1px solid #eee;">
                                <span>${m.name} (+₱${m.price})</span>
                                <input type="checkbox" class="mod-cb" value="${m.id}" data-name="${m.name}" data-price="${m.price}">
                            </label>`).join('')}</div>`,
                        preConfirm: () => Array.from(document.querySelectorAll('.mod-cb:checked')).map(i => ({ id: parseInt(i.value), name: i.dataset.name, price: parseFloat(i.dataset.price) })),
                        confirmButtonColor: '#6B4226'
                    });
                    if (selectedMods) item.modifiers = selectedMods;
                }
            }

            // Combine into cart logic (group identical items)
            const modKey = item.modifiers.map(m => m.id).sort().join(',');
            const uniqueKey = `${item.id}-${item.variation_id}-${modKey}`;
            const existingIndex = state.cart.findIndex(i => `${i.id}-${i.variation_id}-${i.modifiers.map(m => m.id).sort().join(',')}` === uniqueKey);

            if (existingIndex > -1) state.cart[existingIndex].qty += 1;
            else state.cart.push(item);
            
            updateCartUI();
        }

        function updateCartUI() {
            const btn = document.getElementById('cartBtn');
            if (state.cart.length > 0) {
                let total = 0; let count = 0;
                state.cart.forEach(i => {
                    const mCost = i.modifiers.reduce((s, m) => s + m.price, 0);
                    total += (i.price + mCost) * i.qty;
                    count += i.qty;
                });
                document.getElementById('cartCount').innerText = count;
                document.getElementById('cartTotal').innerText = '₱' + total.toFixed(2);
                btn.style.display = 'flex';
            } else {
                btn.style.display = 'none';
            }
        }

        // --- CART REVIEW AND LOGIN GATE ---
        async function reviewCart() {
            if (!isLoggedIn) {
                const { value: formValues } = await Swal.fire({
                    title: 'Login to Order',
                    html: `
                        <p style="font-size:0.9rem;">Enter your verified phone & PIN.</p>
                        <input id="login-phone" class="swal2-input" placeholder="Phone Number">
                        <input id="login-pin" type="password" class="swal2-input" placeholder="4-Digit PIN">
                    `,
                    confirmButtonText: 'Login', confirmButtonColor: '#6B4226',
                    preConfirm: () => ({ phone: document.getElementById('login-phone').value, pin: document.getElementById('login-pin').value })
                });

                if (formValues && formValues.phone && formValues.pin) {
                    Swal.fire({title:'Verifying...', didOpen:()=>Swal.showLoading()});
                    const res = await fetch('auth_customer.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(formValues) });
                    const data = await res.json();
                    if (data.success) location.reload(); else Swal.fire('Error', data.error, 'error');
                }
                return;
            }

            // Build Receipt UI
            let html = '<div style="text-align:left; max-height:300px; overflow-y:auto;">';
            let grandTotal = 0;
            state.cart.forEach((item, idx) => {
                const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
                const lineTotal = (item.price + mCost) * item.qty;
                grandTotal += lineTotal;
                
                let modText = item.modifiers.length > 0 ? `<br><small style="color:gray;">+ ${item.modifiers.map(m=>m.name).join(', ')}</small>` : '';
                
                html += `
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #eee;">
                        <div style="line-height:1.2; flex:1;">
                            <span style="font-weight:bold;">${item.name}</span>
                            ${modText}
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="font-weight:bold;">${item.qty}x</span>
                            <span style="font-weight:bold; color:var(--brand); min-width:60px; text-align:right;">₱${lineTotal.toFixed(2)}</span>
                            <span style="color:red; cursor:pointer; font-size:1.2rem;" onclick="removeCartItem(${idx})">🗑️</span>
                        </div>
                    </div>`;
            });
            html += `</div><h2 style="margin-top:15px; color:var(--brand);">Total: ₱${grandTotal.toFixed(2)}</h2>`;

            Swal.fire({
                title: 'Review Order', html: html,
                showCancelButton: true, confirmButtonText: 'Submit Pick-Up', confirmButtonColor: '#2e7d32'
            }).then((res) => {
                if (res.isConfirmed) submitOrder();
            });
        }

        // Needs to be attached to window so SweetAlert's raw HTML can trigger it
        window.removeCartItem = function(idx) {
            state.cart.splice(idx, 1);
            updateCartUI();
            if(state.cart.length > 0) reviewCart(); else Swal.close();
        };

        async function submitOrder() {
            Swal.fire({title: 'Sending to Kitchen...', didOpen: () => Swal.showLoading()});
            try {
                const res = await fetch('save_public_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ items: state.cart }) // Now contains variation_id and modifiers!
                });
                const data = await res.json();
                if (data.success) {
                    Swal.fire('Order Sent!', 'Order #' + data.order_id + ' received. Please pay at the counter.', 'success');
                    state.cart = []; updateCartUI();
                } else Swal.fire('Error', data.error, 'error');
            } catch (e) { Swal.fire('Error', 'Network error.', 'error'); }
        }

        initApp();
    </script>
</body>
</html>