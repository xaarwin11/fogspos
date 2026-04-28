<?php
require_once '../db.php';
session_start();
if (empty($_SESSION['public_csrf'])) { $_SESSION['public_csrf'] = bin2hex(random_bytes(32)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Order - Fogs Tasas Cafe</title>
    <meta name="csrf-token" content="<?= $_SESSION['public_csrf'] ?>">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap');
        :root { --primary: #6B4226; --primary-light: #8A5A38; --bg-main: #FDFBF7; --card-bg: #FFFFFF; --text-dark: #2D2D2D; --text-muted: #888888; --accent: #2e7d32; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { font-family: 'Nunito', sans-serif; background: var(--bg-main); margin: 0; padding-bottom: 120px; color: var(--text-dark); }
        
        /* Tracker Banner */
        #active-tracker-banner {
            position: sticky; top: 0; background: var(--accent); color: white; padding: 15px 20px;
            display: none; justify-content: space-between; align-items: center; z-index: 100;
            box-shadow: 0 4px 15px rgba(46, 125, 50, 0.4); cursor: pointer;
        }

        .hero { background: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('../assets/img/cafe-bg.jpg') center/cover; padding: 40px 20px 20px; color: white; border-radius: 0 0 24px 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); background-color: #4a3320;}
        .hero h1 { margin: 0; font-size: 28px; font-weight: 900; letter-spacing: -0.5px; }
        .hero p { margin: 5px 0 0 0; font-size: 14px; opacity: 0.9; }
        .status-badge { display: inline-block; background: var(--accent); color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 800; margin-top: 10px; }
        .category-nav { position: sticky; top: 0; background: rgba(253, 251, 247, 0.95); backdrop-filter: blur(10px); z-index: 40; display: flex; overflow-x: auto; padding: 15px 20px; gap: 12px; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .category-nav::-webkit-scrollbar { display: none; }
        .nav-pill { white-space: nowrap; padding: 8px 18px; background: white; border: 1px solid #EBEBEB; border-radius: 20px; font-weight: 700; font-size: 14px; color: var(--text-muted); cursor: pointer; transition: all 0.2s; }
        .nav-pill.active { background: var(--primary); color: white; border-color: var(--primary); }
        .menu-section { padding: 20px; }
        .section-title { font-size: 20px; font-weight: 800; margin-bottom: 15px; }
        .product-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .product-card { background: var(--card-bg); border-radius: 20px; padding: 12px; box-shadow: 0 6px 16px rgba(0,0,0,0.04); cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; }
        .product-card:active { transform: scale(0.97); }
        .product-image { width: 100%; aspect-ratio: 1; border-radius: 14px; background: #F0F0F0; object-fit: cover; margin-bottom: 12px; }
        .product-info h3 { margin: 0 0 4px 0; font-size: 15px; font-weight: 800; line-height: 1.2; }
        .product-info p { margin: 0; color: var(--primary); font-weight: 800; font-size: 15px; }
        .add-btn-small { background: var(--primary-light); color: white; width: 30px; height: 30px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-top: -30px; align-self: flex-end; box-shadow: 0 2px 8px rgba(107, 66, 38, 0.3); }
        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); z-index: 50; opacity: 0; pointer-events: none; transition: opacity 0.3s ease; }
        .overlay.active { opacity: 1; pointer-events: auto; }
        .bottom-sheet { position: fixed; bottom: 0; left: 0; width: 100%; background: white; border-radius: 28px 28px 0 0; z-index: 60; padding: 24px; box-sizing: border-box; max-height: 90vh; overflow-y: auto; transform: translateY(100%); transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.1); }
        .bottom-sheet.active { transform: translateY(0); }
        .sheet-handle { width: 40px; height: 5px; background: #E0E0E0; border-radius: 5px; margin: 0 auto 20px auto; }
        .option-group { margin-bottom: 20px; }
        .option-title { font-size: 16px; font-weight: 800; margin-bottom: 10px; display: flex; justify-content: space-between; }
        .option-title span { color: var(--text-muted); font-size: 12px; font-weight: 600; background: #F0F0F0; padding: 2px 8px; border-radius: 8px;}
        .radio-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .radio-btn { border: 2px solid #EBEBEB; border-radius: 12px; padding: 12px; text-align: center; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: all 0.2s; }
        .radio-btn.selected { border-color: var(--primary); background: rgba(107, 66, 38, 0.05); color: var(--primary); }
        .checkbox-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #F0F0F0; }
        .checkbox-row:last-child { border-bottom: none; }
        .checkbox-row label { font-weight: 700; font-size: 15px; display: flex; align-items: center; gap: 10px; }
        .checkbox-row input { width: 20px; height: 20px; accent-color: var(--primary); }
        .form-input { width: 100%; padding: 14px; border: 2px solid #eee; border-radius: 12px; margin-bottom: 10px; font-weight: 700; font-family: 'Nunito', sans-serif;}
        .btn-large { width: 100%; background: var(--primary); color: white; border: none; padding: 18px; border-radius: 16px; font-size: 16px; font-weight: 900; margin-top: 10px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .btn-large:disabled { opacity: 0.7; cursor: not-allowed; }
        .floating-cart { position: fixed; bottom: env(safe-area-inset-bottom, 20px); left: 20px; right: 20px; background: var(--primary); color: white; padding: 16px 20px; border-radius: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 8px 25px rgba(107, 66, 38, 0.4); z-index: 45; transform: translateY(150%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.2); cursor: pointer; }
        .floating-cart.visible { transform: translateY(0); }
        .cart-info { font-weight: 800; font-size: 16px; }
        .cart-action { font-weight: 900; background: rgba(255,255,255,0.2); padding: 8px 16px; border-radius: 12px; }
    </style>
</head>
<body>

    <div id="active-tracker-banner" onclick="openTrackerSheet()">
        <div style="font-weight: 800; font-size: 16px;">🔥 <span id="banner-status-text">Preparing Order...</span></div>
        <div style="font-weight: 900; background: rgba(255,255,255,0.2); padding: 6px 12px; border-radius: 8px; font-size: 14px;">View</div>
    </div>

    <div class="hero">
        <h1>Fogs Tasas Cafe</h1>
        <p>San Esteban, Ilocos Sur</p>
        <div class="status-badge">🟢 Open for Pickup</div>
    </div>

    <div id="category-nav" class="category-nav"></div>
    <div id="menu-container"></div>

    <div id="floating-cart" class="floating-cart" onclick="goToCheckout()">
        <div class="cart-info">🛒 <span id="cart-count">0</span> Items</div>
        <div class="cart-action">Checkout ₱<span id="cart-total">0.00</span></div>
    </div>

    <div id="overlay" class="overlay" onclick="closePanel()"></div>

    <div id="tracker-sheet" class="bottom-sheet" style="z-index: 75;">
        <div class="sheet-handle"></div>
        <h2 style="margin-top: 0; font-size: 24px; font-weight: 900;">Order Status</h2>
        <p style="color: var(--text-muted); font-weight: 700; margin-top: -5px;">Ref: <span id="tracker-ref-code">---</span></p>

        <div style="background: #f9f9f9; padding: 25px; border-radius: 16px; margin: 20px 0; text-align: center; border: 1px solid #eee;">
            <div id="tracker-icon" style="font-size: 45px; margin-bottom: 10px;">☕</div>
            <h3 id="tracker-headline" style="margin: 0; color: var(--primary); font-size: 20px;">We received your order!</h3>
            <p id="tracker-subtext" style="margin: 8px 0 0 0; font-size: 14px; color: var(--text-muted); font-weight: 600;">The kitchen is preparing it now. Please wait until the status changes to Ready.</p>
        </div>

        <button class="btn-large" onclick="closePanel()" style="background: #EBEBEB; color: var(--text-dark); justify-content: center;">Close Window</button>
    </div>

    <div id="product-sheet" class="bottom-sheet">
        <div class="sheet-handle"></div>
        <h2 id="sheet-title" style="margin-top: 0; font-size: 24px; font-weight: 900;">Product</h2>
        <p id="sheet-base-price" style="color: var(--primary); font-weight: 800; font-size: 18px; margin-top: -5px;">₱0.00</p>
        <input type="hidden" id="temp-name">
        <input type="hidden" id="temp-price">
        <div id="dynamic-options"></div>
        <button class="btn-large" onclick="addToCart()">
            <span>Add to Order</span>
            <span id="sheet-total-btn">₱0.00</span>
        </button>
    </div>

    <div id="checkout-sheet" class="bottom-sheet" style="z-index: 70;">
        <div class="sheet-handle"></div>
        <h2 style="margin-top: 0; font-size: 22px; font-weight: 900;">Review Order</h2>
        <div id="checkout-items" style="max-height: 25vh; overflow-y: auto; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;"></div>
        <div style="display: flex; justify-content: space-between; font-weight: 900; font-size: 18px; margin-bottom: 20px;">
            <span>Total:</span>
            <span id="checkout-total-price" style="color: var(--primary);">₱0.00</span>
        </div>

        <div style="display: flex; background: #F0F0F0; border-radius: 12px; padding: 4px; margin-bottom: 20px;">
            <button id="tab-guest-btn" onclick="switchCheckoutTab('guest')" style="flex: 1; padding: 10px; border-radius: 10px; border: none; font-weight: 800; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); color: var(--text-dark);">Guest</button>
            <button id="tab-account-btn" onclick="switchCheckoutTab('account')" style="flex: 1; padding: 10px; border-radius: 10px; border: none; font-weight: 800; background: transparent; color: var(--text-muted);">Cafe Member</button>
        </div>

        <div id="flow-guest">
            <input type="text" id="guest-name" placeholder="Name for the order" class="form-input">
            <input type="tel" id="guest-phone" placeholder="Phone Number (Optional)" class="form-input">
        </div>

        <div id="flow-account" style="display: none;">
            <input type="tel" id="account-phone" placeholder="Mobile Number (09...)" class="form-input">
            <input type="password" id="account-pin" placeholder="Password / PIN" class="form-input">
            <p style="font-size: 12px; color: var(--text-muted); margin-top: -5px; margin-bottom: 15px;">*New numbers auto-create an account.</p>
        </div>

        <div style="background: #FFF8F5; border: 1px solid #F5E6DF; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <label style="font-weight: 800; color: var(--primary);">Apply SC/PWD Discount</label>
                <input type="checkbox" id="sc-toggle" onchange="toggleSCForm()" style="width: 20px; height: 20px; accent-color: var(--primary);">
            </div>
            <div id="sc-form-fields" style="display: none; margin-top: 15px;">
                <select id="sc-type" class="form-input" style="margin-bottom: 10px;"><option value="SC">Senior Citizen</option><option value="PWD">PWD</option></select>
                <input type="text" id="sc-name" placeholder="Full Name on ID" class="form-input" style="margin-bottom: 10px;">
                <input type="text" id="sc-idnum" placeholder="ID Number" class="form-input" style="margin-bottom: 10px;">
                <p style="font-size: 12px; color: var(--primary); font-weight: 800; margin-top: 5px;">*Please present your ID upon pickup.</p>
            </div>
        </div>

        <button class="btn-large" onclick="submitFinalOrder()" style="background: var(--accent); justify-content: center;">Place Order</button>
    </div>

    <script>
        let cart = [];
        let globalMenu = []; 
        let currentProductId = null;
        let currentBasePrice = 0;
        let currentVariationName = '';
        let currentVariationPrice = 0;
        let activeTab = 'guest';

        // ORDER TRACKING LOGIC
        let activeOrderRef = localStorage.getItem('fogs_active_order');
        let trackerInterval = null;

        document.addEventListener("DOMContentLoaded", () => {
            loadMenu();
            if (activeOrderRef) {
                startOrderTracking();
            }
        });

        async function loadMenu() {
            try {
                const res = await fetch('../api/fetch_menu.php');
                const data = await res.json();
                if (!data.success) { document.getElementById('menu-container').innerHTML = `<p style="text-align:center; padding: 50px;">Failed to load menu.</p>`; return; }
                globalMenu = data.menu; 
                const navContainer = document.getElementById('category-nav');
                const menuContainer = document.getElementById('menu-container');
                navContainer.innerHTML = ''; menuContainer.innerHTML = '';

                globalMenu.forEach((category, index) => {
                    const isActive = index === 0 ? 'active' : '';
                    navContainer.innerHTML += `<div class="nav-pill ${isActive}" onclick="scrollToCategory('cat-${category.id}', this)">${category.name}</div>`;
                    let productsHTML = '';
                    category.products.forEach(prod => {
                        productsHTML += `
                            <div class="product-card" onclick="openProduct(${prod.id})">
                                <img class="product-image" src="../assets/img/${prod.image_url || 'placeholder.jpg'}" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'100\\' height=\\'100\\'><rect width=\\'100\\' height=\\'100\\' fill=\\'%23eee\\'/></svg>'">
                                <div class="product-info"><h3>${prod.name}</h3><p>₱${prod.price.toFixed(2)}</p></div>
                                <div class="add-btn-small">+</div>
                            </div>
                        `;
                    });
                    menuContainer.innerHTML += `<div id="cat-${category.id}" class="menu-section" style="padding-top: 80px; margin-top: -60px;"><div class="section-title">${category.name}</div><div class="product-grid">${productsHTML}</div></div>`;
                });
            } catch (err) { console.error("Failed to load menu:", err); }
        }

        // --- TRACKING ENGINE ---
        function startOrderTracking() {
            document.getElementById('active-tracker-banner').style.display = 'flex';
            document.getElementById('tracker-ref-code').innerText = activeOrderRef;
            
            // Check immediately, then every 10 seconds
            checkOrderStatus();
            trackerInterval = setInterval(checkOrderStatus, 10000);
        }

        async function checkOrderStatus() {
            if (!activeOrderRef) return;
            try {
                const res = await fetch(`../api/track_order.php?ref=${activeOrderRef}`);
                const data = await res.json();
                
                if (data.success) {
                    const status = data.order.status.toLowerCase();
                    const banner = document.getElementById('active-tracker-banner');
                    const bannerText = document.getElementById('banner-status-text');
                    const headline = document.getElementById('tracker-headline');
                    const subtext = document.getElementById('tracker-subtext');
                    const icon = document.getElementById('tracker-icon');

                    if (status === 'open' || status === 'pending') {
                        // STATE 1: Unconfirmed
                        banner.style.background = "#E65100"; // Alert Orange
                        bannerText.innerText = "Waiting for Confirmation...";
                        headline.innerText = "We received your order!";
                        subtext.innerText = "Waiting for the cafe staff to confirm and accept it.";
                        icon.innerText = "⏳";
                    } 
                    else if (status === 'preparing') {
                        // STATE 2: Staff Clicked "Accept"
                        banner.style.background = "#2e7d32"; // Action Green
                        bannerText.innerText = "Preparing Order...";
                        headline.innerText = "Kitchen is preparing your order!";
                        subtext.innerText = "Our baristas are on it. We'll let you know when it's ready.";
                        icon.innerText = "🔥";
                    } 
                    else if (status === 'ready') {
                        // STATE 3: Staff Clicked "Ready"
                        banner.style.background = "#1976D2"; // Ready Blue
                        bannerText.innerText = "Order Ready!";
                        headline.innerText = "Your order is ready for pickup!";
                        subtext.innerText = "Please head to the counter to claim your order.";
                        icon.innerText = "✅";
                    }
                    else if (status === 'completed' || status === 'paid') {
                        // STATE 4: Customer picked it up
                        localStorage.removeItem('fogs_active_order');
                        clearInterval(trackerInterval);
                        banner.style.display = 'none';
                        closePanel();
                    }
                    else if (status === 'voided' || status === 'cancelled') {
                        // STATE 5: Rejected by staff
                        alert(`Order ${activeOrderRef} was cancelled by the cafe.`);
                        localStorage.removeItem('fogs_active_order');
                        clearInterval(trackerInterval);
                        banner.style.display = 'none';
                        closePanel();
                    }
                }
            } catch (err) { console.error("Tracking error:", err); }
        }

        function openTrackerSheet() {
            document.getElementById('overlay').classList.add('active');
            document.getElementById('tracker-sheet').classList.add('active');
        }

        function scrollToCategory(id, element) {
            document.querySelectorAll('.nav-pill').forEach(el => el.classList.remove('active'));
            element.classList.add('active');
            document.getElementById(id).scrollIntoView({ behavior: 'smooth' });
        }

        function openProduct(id) {
            if(activeOrderRef) return alert("You already have an active order preparing. Please wait for it to finish!");

            let product = null;
            for (let cat of globalMenu) { product = cat.products.find(p => Number(p.id) === Number(id)); if (product) break; }
            if (!product) return;

            currentProductId = product.id; currentBasePrice = product.price; currentVariationName = ''; currentVariationPrice = 0;
            document.getElementById('sheet-title').innerText = product.name; document.getElementById('temp-name').value = product.name;
            document.getElementById('sheet-base-price').innerText = 'Base: ₱' + product.price.toFixed(2);
            
            let optionsHTML = '';
            if (product.variations && product.variations.length > 0) {
                optionsHTML += `<div class="option-group"><div class="option-title">Size <span>Required</span></div><div class="radio-grid">`;
                product.variations.forEach((v, idx) => {
                    const isSelected = idx === 0 ? 'selected' : ''; 
                    if (idx === 0) { currentVariationName = v.name; currentVariationPrice = v.price; }
                    optionsHTML += `<div class="radio-btn ${isSelected}" onclick="selectVariation(this, ${v.price}, '${v.name.replace(/'/g, "\\'")}')">${v.name} (+₱${v.price.toFixed(2)})</div>`;
                });
                optionsHTML += `</div></div>`;
            }

            if (product.modifiers && product.modifiers.length > 0) {
                optionsHTML += `<div class="option-group"><div class="option-title">Add-ons <span>Optional</span></div>`;
                product.modifiers.forEach(m => {
                    optionsHTML += `<div class="checkbox-row"><label><input type="checkbox" class="addon-check" value="${m.price}" data-name="${m.name.replace(/'/g, "\\'")}"> ${m.name}</label><span style="font-weight: 700; color: var(--text-muted);">+₱${m.price.toFixed(2)}</span></div>`;
                });
                optionsHTML += `</div>`;
            }

            document.getElementById('dynamic-options').innerHTML = optionsHTML;
            document.querySelectorAll('.addon-check').forEach(cb => { cb.addEventListener('change', updateSheetTotal); });
            updateSheetTotal();
            
            document.getElementById('overlay').classList.add('active');
            document.getElementById('product-sheet').classList.add('active');
        }

        function closePanel() {
            document.getElementById('overlay').classList.remove('active');
            document.getElementById('product-sheet').classList.remove('active');
            document.getElementById('checkout-sheet').classList.remove('active');
            document.getElementById('tracker-sheet').classList.remove('active');
        }

        function selectVariation(element, price, name) {
            element.parentElement.querySelectorAll('.radio-btn').forEach(btn => btn.classList.remove('selected'));
            element.classList.add('selected');
            currentVariationPrice = price; currentVariationName = name; updateSheetTotal();
        }

        function updateSheetTotal() {
            let total = currentBasePrice + currentVariationPrice;
            document.querySelectorAll('.addon-check:checked').forEach(cb => { total += parseFloat(cb.value); });
            document.getElementById('sheet-total-btn').innerText = '₱' + total.toFixed(2); document.getElementById('temp-price').value = total;
        }

        function addToCart() {
            let selectedMods = [];
            document.querySelectorAll('.addon-check:checked').forEach(cb => { selectedMods.push(cb.getAttribute('data-name')); });
            cart.push({ id: currentProductId, name: document.getElementById('temp-name').value, price: parseFloat(document.getElementById('temp-price').value), qty: 1, variation: currentVariationName, modifiers: selectedMods });
            updateCartUI(); closePanel();
        }

        function removeCartItem(index) { cart.splice(index, 1); updateCartUI(); if (cart.length > 0) goToCheckout(); else closePanel(); }

        function updateCartUI() {
            if (cart.length > 0) {
                document.getElementById('floating-cart').classList.add('visible');
                document.getElementById('cart-count').innerText = cart.length;
                document.getElementById('cart-total').innerText = cart.reduce((sum, item) => sum + item.price, 0).toFixed(2);
            } else {
                document.getElementById('floating-cart').classList.remove('visible');
            }
        }

        function goToCheckout() {
            const container = document.getElementById('checkout-items'); container.innerHTML = ''; let total = 0;
            cart.forEach((item, index) => {
                total += item.price * item.qty;
                let detailText = item.variation ? `(${item.variation}) ` : '';
                if (item.modifiers.length > 0) detailText += `+ ${item.modifiers.join(', ')}`;
                container.innerHTML += `<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f0;"><div style="flex: 1;"><div style="font-weight: 800; font-size: 15px;">${item.qty}x ${item.name}</div><div style="font-size: 12px; color: var(--text-muted); line-height: 1.2; margin-top: 2px;">${detailText}</div></div><div style="display: flex; flex-direction: column; align-items: flex-end;"><span style="font-weight: 800; color: var(--primary); margin-bottom: 5px;">₱${(item.price * item.qty).toFixed(2)}</span><button onclick="removeCartItem(${index})" style="background: none; border: none; color: #ff4d4f; font-size: 12px; font-weight: 700; cursor: pointer; padding: 0;">Remove</button></div></div>`;
            });
            document.getElementById('checkout-total-price').innerText = '₱' + total.toFixed(2);
            document.getElementById('overlay').classList.add('active');
            document.getElementById('product-sheet').classList.remove('active');
            document.getElementById('checkout-sheet').classList.add('active');
        }

        function switchCheckoutTab(tab) {
            activeTab = tab;
            document.getElementById('flow-guest').style.display = tab === 'guest' ? 'block' : 'none';
            document.getElementById('flow-account').style.display = tab === 'account' ? 'block' : 'none';
            document.getElementById('tab-guest-btn').style.background = tab === 'guest' ? 'white' : 'transparent';
            document.getElementById('tab-guest-btn').style.color = tab === 'guest' ? 'var(--text-dark)' : 'var(--text-muted)';
            document.getElementById('tab-account-btn').style.background = tab === 'account' ? 'white' : 'transparent';
            document.getElementById('tab-account-btn').style.color = tab === 'account' ? 'var(--text-dark)' : 'var(--text-muted)';
        }

        function toggleSCForm() { document.getElementById('sc-form-fields').style.display = document.getElementById('sc-toggle').checked ? 'block' : 'none'; }

        async function submitFinalOrder() {
            if (cart.length === 0) return alert("Your cart is empty!");
            const btn = document.querySelector('#checkout-sheet .btn-large');

            let formData = new FormData();
            formData.append('cart', JSON.stringify(cart));
            formData.append('checkout_type', activeTab);

            if (activeTab === 'guest') {
                const name = document.getElementById('guest-name').value.trim();
                if (!name) return alert("Please enter a name for the order.");
                formData.append('customer_name', name);
                formData.append('customer_phone', document.getElementById('guest-phone').value.trim());
            } else {
                const phone = document.getElementById('account-phone').value.trim();
                const pin = document.getElementById('account-pin').value.trim();
                if (!phone || !pin) return alert("Please enter your mobile number and password.");
                formData.append('account_phone', phone);
                formData.append('account_pin', pin);
            }

            if (document.getElementById('sc-toggle').checked) {
                if (!document.getElementById('sc-name').value.trim() || !document.getElementById('sc-idnum').value.trim()) {
                    return alert("Please fill out the SC/PWD name and ID number.");
                }
                formData.append('sc_type', document.getElementById('sc-type').value);
                formData.append('sc_name', document.getElementById('sc-name').value);
                formData.append('sc_idnum', document.getElementById('sc-idnum').value);
            }

            try {
                btn.innerText = "Sending to Kitchen...";
                btn.disabled = true;
                
                const res = await fetch('../api/save_public_order.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').getAttribute('content') },
                    body: formData 
                });
                
                const data = await res.json();
                
                if (data.success) {
                    // 🚨 THIS IS THE MAGIC! Save the order reference to the phone's memory!
                    localStorage.setItem('fogs_active_order', data.order_id);
                    location.reload(); 
                } else {
                    alert("Error: " + (data.error || 'Something went wrong.'));
                    btn.innerText = "Place Order";
                    btn.disabled = false;
                }
            } catch (err) {
                console.error(err);
                alert("Network error. Please make sure you are connected to the network and try again.");
                btn.innerText = "Place Order";
                btn.disabled = false;
            }
        }
    </script>
</body>
</html>