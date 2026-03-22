let state = {
    products: [], modifiers: [], discounts: [], categories: [],
    cart: [], activeTableId: null, activeOrderId: null,
    mode: 'dine_in', currentCategory: 'All', customer_name: null,
    discount_id: null, discount_amount: 0, discount_note: '', senior_details: [],
    custom_discount: { is_active: false, type: 'percent', val: 0, target: 'all', note: '' },
    amount_paid: 0, grand_total: 0
};

// SECURITY: Helper to grab the CSRF token from the page
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

async function initPOS() {
    try {
        const r = await fetch('../api/get_products.php');
        const data = await r.json();
        if (data.success) {
            state.products = data.products; state.modifiers = data.modifiers;
            state.discounts = data.discounts; state.categories = data.categories;
            renderCategories(); renderProducts();
        }
    } catch (e) { console.error("POS Error:", e); }
}

window.addOpenItem = async function() {
    if (state.mode === 'dine_in' && !state.activeTableId) return Swal.fire('Table Required', 'Select a table first', 'warning');
    if (state.mode === 'takeout' && !state.activeOrderId && state.activeOrderId !== 'new') return Swal.fire('Takeout Required', 'Create a new takeout order first', 'warning');

    const { value: formValues } = await Swal.fire({
        title: '⭐ Custom Item',
        html: `
            <div style="background:#f9fafb; padding:15px; border-radius:10px; border:1px solid #eee; margin-bottom:15px; text-align:left;">
                <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Item Name</label>
                <input type="text" id="oi-name" class="search-bar" placeholder="e.g. Special Pasta Tray" style="margin-bottom:15px; border:2px solid var(--brand); font-weight:bold;">
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div>
                        <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Unit Price (₱)</label>
                        <input type="number" id="oi-price" class="search-bar" placeholder="0.00" step="0.01" style="margin-bottom:0; font-weight:bold; color:var(--brand-dark); font-size:1.2rem;">
                    </div>
                    <div>
                        <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Quantity</label>
                        <input type="number" id="oi-qty" class="search-bar" value="1" min="1" style="margin-bottom:0; font-weight:bold; font-size:1.2rem; text-align:center;">
                    </div>
                </div>
            </div>

            <div style="text-align:left;">
                <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Kitchen Notes (Optional)</label>
                <textarea id="oi-note" placeholder="e.g. Allergy, extra spicy..." style="width:100%; box-sizing:border-box; margin-top:5px; padding:10px; border-radius:8px; border:1px solid #ccc; font-family:inherit; min-height:80px; resize:vertical;"></textarea>
            </div>
        `,
        focusConfirm: false, showCancelButton: true, confirmButtonText: '+ Add to Cart', confirmButtonColor: '#2e7d32', width: 500,
        preConfirm: () => {
            const name = document.getElementById('oi-name').value;
            const price = parseFloat(document.getElementById('oi-price').value);
            const qty = parseInt(document.getElementById('oi-qty').value);
            const note = document.getElementById('oi-note').value;
            
            if (!name || isNaN(price) || isNaN(qty) || qty < 1) {
                Swal.showValidationMessage('Please enter a valid name, price, and quantity.');
                return false;
            }
            return { name, price, qty, note };
        }
    });

    if (formValues) {
        let item = {
            id: 'custom_item', 
            name: '⭐ ' + formValues.name, 
            price: formValues.price,
            qty: formValues.qty,
            variation_id: null, variation_name: null, modifiers: [],
            item_notes: formValues.note || null,
            discount_amount: 0, discount_note: ''
        };
        state.cart.push(item);
        renderCart();
    }
};

function renderCategories() {
    const con = document.getElementById('catContainer');
    if (!con) return;
    let html = `<button class="category-tab active" onclick="filterCat('All')">All</button>`;
    state.categories.forEach(c => html += `<button class="category-tab" onclick="filterCat('${c.name}')">${c.name}</button>`);
    con.innerHTML = html;
}

function filterCat(name) {
    state.currentCategory = name;
    document.querySelectorAll('.category-tab').forEach(b => b.classList.toggle('active', b.innerText === name));
    renderProducts();
}

function renderProducts() {
    const con = document.getElementById('prodContainer');
    if (!con) return;
    const term = document.getElementById('search') ? document.getElementById('search').value.toLowerCase() : '';
    con.innerHTML = '';
    
    const filtered = state.products.filter(p => (state.currentCategory === 'All' || p.category_name === state.currentCategory) && p.name.toLowerCase().includes(term));
    
    // THE UPGRADE: Group items by category with headers if viewing "All"
    if (state.currentCategory === 'All' && term === '') {
        state.categories.forEach(cat => {
            const catProducts = filtered.filter(p => p.category_name === cat.name);
            
            if (catProducts.length > 0) {
                // 1. Inject a beautiful Section Header
                const header = document.createElement('div');
                header.style = "grid-column: 1 / -1; font-weight: 800; color: var(--brand); text-transform: uppercase; margin-top: 15px; border-bottom: 2px solid var(--border); padding-bottom: 5px; font-size: 1.1rem; letter-spacing: 1px;";
                header.innerText = "☕ " + cat.name; // You can change the emoji!
                con.appendChild(header);
                
                // 2. Render the products under this header
                catProducts.forEach(p => {
                    const el = document.createElement('div'); el.className = 'product-card';
                    el.innerHTML = `<div>${p.name}</div><div style="color:var(--brand)">₱${parseFloat(p.price).toFixed(2)}</div>`;
                    el.onclick = () => handleProductSelection(p);
                    con.appendChild(el);
                });
            }
        });
    } 
    // Normal rendering for searching or viewing a single category tab
    else {
        filtered.forEach(p => {
            const el = document.createElement('div'); el.className = 'product-card';
            el.innerHTML = `<div>${p.name}</div><div style="color:var(--brand)">₱${parseFloat(p.price).toFixed(2)}</div>`;
            el.onclick = () => handleProductSelection(p);
            con.appendChild(el);
        });
    }
}

// ============================================================================
// REUSABLE HELPER: Now includes Item Notes!
// ============================================================================
window.selectProductOptions = async function(p, preselectedVarId = null, preselectedModIds = [], preselectedNote = '', showNotes = true) {
    let result = { canceled: false, variation_id: null, variation_name: null, price: parseFloat(p.price), modifiers: [], item_notes: preselectedNote };

    let htmlContent = '';
    let hasVariations = p.variations && p.variations.length > 0;

    // 1. BUILD SIZES / VARIATIONS HTML
    if (hasVariations) {
        htmlContent += `
            <div style="font-weight:bold; text-align:left; margin-bottom:5px; color:var(--brand-dark);">Select Size:</div>
            <div class="var-grid" style="margin-bottom: 20px;">
                ${p.variations.map(v => {
                    let isActive = (preselectedVarId == v.id) ? 'active' : '';
                    return `<div class="var-btn size-btn ${isActive}" data-id="${v.id}" onclick="document.querySelectorAll('.size-btn').forEach(b=>b.classList.remove('active')); this.classList.add('active'); document.getElementById('swal-v').value=this.dataset.id;">${v.name}<span class="price">₱${parseFloat(v.price).toFixed(2)}</span></div>`
                }).join('')}
            </div>
            <input type="hidden" id="swal-v" value="${preselectedVarId || ''}">
        `;
    }

    // 2. BUILD ADD-ONS / MODIFIERS HTML
    const pMods = p.modifiers || [];
    const allowed = state.modifiers.filter(m => pMods.includes(Number(m.id)) || pMods.includes(String(m.id)));
    
    if (allowed.length > 0) {
        htmlContent += `<div style="font-weight:bold; text-align:left; margin-bottom:5px; color:var(--brand-dark);">Add-ons:</div>`;
        htmlContent += `<div class="swal-list" style="margin-bottom: 20px;">${allowed.map(m => {
            const isChecked = preselectedModIds.includes(Number(m.id)) ? 'checked' : '';
            return `<label class="swal-check" style="display:flex; justify-content:space-between; padding:10px; border-bottom:1px solid #eee;"><span>${m.name} (+₱${m.price})</span><input type="checkbox" class="mod-cb" value="${m.id}" data-name="${m.name}" data-price="${m.price}" ${isChecked}></label>`;
        }).join('')}</div>`;
    }

    // 3. BUILD NOTES HTML (Only if showNotes is true)
    if (showNotes) {
        htmlContent += `
            <div style="font-weight:bold; text-align:left; margin-bottom:5px; color:var(--brand-dark);">Special Instructions:</div>
            <textarea id="swal-note" placeholder="e.g. No onions, half sweet..." style="width:100%; box-sizing:border-box; padding:10px; border-radius:8px; border:1px solid #ccc; font-family:inherit; min-height:80px; resize:vertical;">${preselectedNote || ''}</textarea>
        `;
    } else {
        htmlContent += `<input type="hidden" id="swal-note" value="">`;
    }

    // FAST-TRACK: If item has no sizes, no add-ons, and notes are hidden, skip popup entirely!
    if (!hasVariations && allowed.length === 0 && !showNotes) {
        return result; 
    }

    const titleText = preselectedModIds.length > 0 || preselectedNote || preselectedVarId ? 'Edit Item' : 'Customize Item';

    // 4. FIRE THE SINGLE COMBINED MODAL
    const { value: optionsData, isDismissed } = await Swal.fire({
        title: titleText,
        html: htmlContent,
        preConfirm: () => {
            let vId = null;
            if (hasVariations) {
                vId = document.getElementById('swal-v').value;
                if (!vId) {
                    Swal.showValidationMessage('Please select a size');
                    return false;
                }
            }

            let mods = [];
            if (allowed.length > 0) {
                mods = Array.from(document.querySelectorAll('.mod-cb:checked')).map(i => ({ id: parseInt(i.value), name: i.dataset.name, price: parseFloat(i.dataset.price) }));
            }

            return { vId: vId, mods: mods, note: document.getElementById('swal-note').value };
        },
        confirmButtonColor: '#6B4226', 
        showCancelButton: true
    });
    
    if (isDismissed || !optionsData) return { canceled: true };
    
    // Apply selected variation
    if (hasVariations) {
        const selectedVar = p.variations.find(v => v.id == optionsData.vId);
        result.variation_id = selectedVar.id;
        result.variation_name = selectedVar.name;
        result.price = parseFloat(selectedVar.price);
    }

    // Apply modifiers and notes
    result.modifiers = optionsData.mods || [];
    result.item_notes = optionsData.note || null;
    
    return result;
};

window.handleProductSelection = async function(p) {
    if (state.mode === 'dine_in' && !state.activeTableId) return Swal.fire('Table Required', 'Select a table first', 'warning');
    if (state.mode === 'takeout' && !state.activeOrderId && state.activeOrderId !== 'new') return Swal.fire('Takeout Required', 'Create a new takeout order first', 'warning');

    const options = await window.selectProductOptions(p, null, [], '', false);
    if (options.canceled) return;

    let item = { id: p.id, name: p.name, price: options.price, qty: 1, variation_id: null, variation_name: null, modifiers: [], item_notes: null, discount_amount: 0, discount_note: '' };

    if (options.variation_id) {
        item.variation_id = options.variation_id;
        item.variation_name = options.variation_name;
        item.name = `${p.name} (${options.variation_name})`;
    }
    item.modifiers = options.modifiers;
    item.item_notes = options.item_notes;

    // 🌟 THE "CORKAGE" (VARIABLE PRICE) INTERCEPTOR 🌟
    // If the database price is exactly 0 and there are no sizes/add-ons...
    if (parseFloat(p.price) === 0 && !item.variation_id && item.modifiers.length === 0) {
        const { value: openPriceData } = await Swal.fire({
            title: p.name,
            html: `
                <div style="font-size:0.9rem; color:gray; margin-bottom:10px;">Enter the amount for this variable item.</div>
                <input type="number" id="op-price" class="search-bar" placeholder="Amount (₱)" step="0.01" style="font-size:1.5rem; text-align:center; font-weight:bold; color:var(--brand-dark);">
                <input type="text" id="op-note" class="search-bar" placeholder="Details (e.g. Big Wine, Fish)" style="margin-bottom:0;">
            `,
            focusConfirm: false, showCancelButton: true, confirmButtonText: 'Add to Cart', confirmButtonColor: '#6B4226',
            preConfirm: () => {
                const pr = parseFloat(document.getElementById('op-price').value);
                if (isNaN(pr) || pr < 0) { Swal.showValidationMessage('Enter a valid amount'); return false; }
                return { price: pr, note: document.getElementById('op-note').value };
            }
        });

        if (!openPriceData) return; 
        item.price = openPriceData.price;
        item.item_notes = openPriceData.note ? openPriceData.note : 'Variable Amount';
    }

    const modKey = item.modifiers.map(m => m.id).sort().join(',');
    // We add the price to the unique key so Corkage 100 and Corkage 150 don't merge together!
    const uniqueKey = `${item.id}-${item.variation_id}-${item.price}-${modKey}-${item.item_notes || ''}`;
    const existingIndex = state.cart.findIndex(i => `${i.id}-${i.variation_id}-${i.price}-${i.modifiers.map(m => m.id).sort().join(',')}-${i.item_notes || ''}` === uniqueKey);

    if (existingIndex > -1) state.cart[existingIndex].qty += 1;
    else state.cart.push(item);
    
    renderCart();
};

// ============================================================================
// RENDER CART (Performance patched using an Array instead of innerHTML loops)
// ============================================================================
window.renderCart = function() {
    const con = document.getElementById('cartContainer');
    if (!con) return;
    
    let rawSubtotal = 0;
    let localItemDiscountSum = 0;

    if (state.cart.length === 0) {
        con.innerHTML = '<div style="text-align:center; padding:40px; color:#ccc; font-size:1.1rem;">🛒 Cart is empty</div>';
    } else {
        let cartHTML = []; // FIX: Memory Array for lightning-fast DOM injection
        
        state.cart.forEach((item, idx) => {
            const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
            const rawLineTotal = (item.price + mCost) * item.qty;
            rawSubtotal += rawLineTotal;
            
            localItemDiscountSum += parseFloat(item.discount_amount) || 0;
            const discountBadge = item.discount_amount > 0 ? `<div style="font-size:0.75rem; color:var(--danger); margin-top:2px;">Includes ${item.discount_note || 'Discount'}</div>` : '';

            cartHTML.push(`
                <div class="bill-item" style="padding: 10px 0; border-bottom: 1px dashed var(--border); display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <div style="flex:1; line-height: 1.2;">
                        <div style="font-weight:700; font-size:0.95rem; cursor:pointer; color:var(--text-main);" onclick="editCartItem(${idx})" title="Tap to edit">${item.name}</div>
                        
                        ${item.modifiers.length > 0 ? `<div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">+ ${item.modifiers.map(m => m.name).join(', ')}</div>` : ''}
                        
                        ${item.item_notes ? `<div style="font-size:0.8rem; color:#d97706; margin-top:2px;">📝 ${item.item_notes}</div>` : ''}
                        
                        ${discountBadge}
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; background:#f3f4f6; padding:4px 8px; border-radius:20px;">
                        <span style="font-size:1.1rem; font-weight:bold; color:var(--brand-dark); cursor:pointer; padding:0 5px;" onclick="updateQty(${idx}, -1)">−</span>
                        <span style="font-weight:bold; min-width:15px; text-align:center;">${item.qty}</span>
                        <span style="font-size:1.1rem; font-weight:bold; color:var(--brand-dark); cursor:pointer; padding:0 5px;" onclick="updateQty(${idx}, 1)">+</span>
                    </div>
                    <div style="text-align:right; min-width: 65px;">
                        <div style="font-weight:800; color:var(--brand); cursor:pointer;" onclick="promptItemDiscount(${idx})" title="Add Item Disc">₱${rawLineTotal.toFixed(2)}</div>
                        <div style="color:var(--danger); font-size:0.7rem; font-weight:bold; cursor:pointer; margin-top:4px;" onclick="confirmRemoveItem(${idx})">✕ DEL</div>
                    </div>
                </div>
            `);
        });
        
        con.innerHTML = cartHTML.join(''); // Push to DOM exactly ONCE
        
        const btnTransfer = document.getElementById('btnTransfer');
        if (btnTransfer) {
            btnTransfer.style.display = (state.mode === 'dine_in' && state.activeOrderId && state.activeOrderId !== 'new') ? 'block' : 'none';
        }
    }

    // --- Math & Global Discount Logic Remains the Same Below ---
    const totalCombinedDiscount = localItemDiscountSum + (parseFloat(state.discount_amount) || 0);
    state.grand_total = Math.max(0, rawSubtotal - totalCombinedDiscount);

    document.getElementById('txtSubtotal').innerText = '₱' + rawSubtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('txtGrandTotal').innerText = '₱' + state.grand_total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('summaryArea').style.display = rawSubtotal > 0 ? 'block' : 'none';

    let discRow = document.getElementById('appliedDiscountRow');
    if (totalCombinedDiscount > 0) {
        let displayNote = 'Total Discount';
        if (state.discount_id) {
            const d = state.discounts.find(x => x.id == state.discount_id);
            if (d) displayNote = d.name + (d.type === 'percent' ? ` (${parseFloat(d.value)}%)` : '');
        } 
        else if (state.custom_discount.is_active && state.custom_discount.note) {
            displayNote = "Custom: " + state.custom_discount.note;
        } 
        else if (state.discount_note) {
            displayNote = state.discount_note;
        }

        if (!discRow) {
            document.getElementById('summaryArea').insertAdjacentHTML('beforeend', `
                <div id="appliedDiscountRow" class="math-row" style="display:flex; justify-content:space-between; font-size:0.9rem; color:var(--danger); margin-top:5px;">
                    <span id="txtDiscName" style="max-width:70%; text-overflow:ellipsis; white-space:nowrap; overflow:hidden;">${displayNote}</span>
                    <span id="txtDiscAmount">-₱${totalCombinedDiscount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                </div>`);
        } else {
            document.getElementById('txtDiscName').innerText = displayNote;
            document.getElementById('txtDiscAmount').innerText = '-₱' + totalCombinedDiscount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    } else if (discRow) discRow.remove();
};

window.updateQty = function(idx, delta) {
    const item = state.cart[idx];
    const newQty = item.qty + delta;
    
    // THE MATH: Differentiate between printed and unprinted
    const printed = parseInt(item.kitchen_printed) || 0;
    const unprinted = item.qty - printed;

    if (delta < 0) {
        // They are reducing quantity. Do they have unprinted items?
        if (unprinted > 0) {
            // Yes! They can safely reduce the unprinted amount without a PIN
            if (newQty <= 0) return confirmRemoveItem(idx); // Failsafe if it hits 0
            state.cart[idx].qty = newQty;
            renderCart();
            return;
        } else {
            // Unprinted is 0. They are trying to reduce a PRINTED item! Pop the PIN!
            return confirmRemoveItem(idx);
        }
    }

    // Normal behavior for increasing quantity (+)
    state.cart[idx].qty = newQty;
    renderCart();
};

window.confirmRemoveItem = async function(idx) {
    const item = state.cart[idx];
    const printed = parseInt(item.kitchen_printed) || 0;
    const unprinted = item.qty - printed;

    // 1. If NO items were printed to the kitchen, just delete it instantly!
    if (printed === 0) {
        Swal.fire({
            title: 'Remove Item?', text: item.name, icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Yes'
        }).then((res) => { if (res.isConfirmed) { state.cart.splice(idx, 1); renderCart(); } });
        return;
    }

    // 2. 🚨 HAS PRINTED ITEMS! Pop the Smart Void Modal.
    const { value: formValues } = await Swal.fire({
        title: `Void ${item.name}?`,
        html: `
            <div style="font-size:0.9rem; color:var(--danger); margin-bottom:15px; font-weight:bold;">
                ${printed} unit(s) active in kitchen. ${unprinted} unit(s) unprinted.
            </div>
            <div style="text-align:left; margin-bottom:10px;">
                <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Quantity to Void (Max: ${item.qty})</label>
                <input type="number" id="void-qty" class="swal2-input" min="1" max="${item.qty}" value="${item.qty}" style="margin-top:5px; text-align:center; font-weight:bold; font-size:1.5rem; color:var(--brand);">
            </div>
            <div id="auth-section">
                <input type="text" id="void-reason" class="swal2-input" placeholder="Reason (e.g., Customer changed mind)" style="margin-top:0;">
                <input type="password" id="void-pin" class="swal2-input" placeholder="Manager PIN" inputmode="numeric">
            </div>
        `,
        icon: 'warning', showCancelButton: true, confirmButtonText: 'Authorize / Remove', confirmButtonColor: '#d33',
        didOpen: () => {
            // MAGIC UI: Dynamically hide the PIN box if they only delete unprinted items!
            const qtyInput = document.getElementById('void-qty');
            const authSec = document.getElementById('auth-section');
            const checkAuth = () => { authSec.style.display = (parseInt(qtyInput.value) > unprinted) ? 'block' : 'none'; };
            qtyInput.addEventListener('input', checkAuth);
            checkAuth();
        },
        preConfirm: () => {
            const vQty = parseInt(document.getElementById('void-qty').value);
            const reason = document.getElementById('void-reason').value;
            const pin = document.getElementById('void-pin').value;
            
            if (isNaN(vQty) || vQty < 1 || vQty > item.qty) { Swal.showValidationMessage('Invalid quantity!'); return false; }
            
            const needsAuth = vQty > unprinted;
            if (needsAuth && (!reason || !pin)) { Swal.showValidationMessage('Reason and PIN are required for printed items!'); return false; }
            
            return { vQty, reason, pin, needsAuth };
        }
    });

    if (formValues) {
        const executeRemoval = async () => {
            let voidedName = item.name;
            let currentOrderId = state.activeOrderId;

            if (formValues.vQty === item.qty) state.cart.splice(idx, 1);
            else state.cart[idx].qty -= formValues.vQty;
            
            if (state.cart.length === 0 && currentOrderId && currentOrderId !== 'new') {
                await fetch('../api/clear_order.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                    body: JSON.stringify({order_id: currentOrderId, reason: formValues.reason || 'Cart Cleared', pin: formValues.pin || ''})
                });
                state.activeTableId = null; state.activeOrderId = null; state.grand_total = 0;
                document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
            } else if (currentOrderId && currentOrderId !== 'new') {
                await window.saveOrder(true, formValues.reason);
            }

            // Only alert the kitchen printer if PRINTED items were killed
            if (formValues.needsAuth) {
                const printedVoided = formValues.vQty - unprinted; 
                fetch(`../api/print_order.php?order_id=${currentOrderId}&type=void_item&item_name=${encodeURIComponent(voidedName)}&qty=${printedVoided}&reason=${encodeURIComponent(formValues.reason)}`).catch(e => console.error(e));
            }

            Swal.fire('Voided!', `${formValues.vQty}x ${voidedName} removed successfully.`, 'success');
            renderCart();
            if (state.cart.length === 0 && typeof showTablePopup === 'function' && state.mode !== 'takeout') showTablePopup();
        };

        if (formValues.needsAuth) {
            Swal.fire({title:'Authorizing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
            const authRes = await fetch('../api/auth_login.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ passcode: formValues.pin })
            });
            const authData = await authRes.json();
            if (authData.success && (authData.user.role === 'admin' || authData.user.role === 'manager')) { await executeRemoval(); } 
            else { Swal.fire('Declined', 'Invalid PIN or you do not have Manager privileges.', 'error'); }
        } else {
            await executeRemoval(); // No auth needed, just do it!
        }
    }
};

window.editCartItem = async function(idx) {
    const item = state.cart[idx];

    // 🌟 BUG FIX: INTERCEPT CUSTOM ITEMS 🌟
    if (item.id === 'custom_item') {
        const cleanName = item.name.replace('⭐ ', ''); // Remove the star for editing
        const { value: formValues } = await Swal.fire({
            title: 'Edit Custom Item',
            html: `
                <div style="text-align:left;">
                    <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Item Name</label>
                    <input type="text" id="oi-edit-name" class="search-bar" value="${cleanName}" style="margin-bottom:10px; font-weight:bold;">
                    
                    <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Unit Price (₱)</label>
                    <input type="number" id="oi-edit-price" class="search-bar" value="${item.price}" step="0.01" style="margin-bottom:10px; font-weight:bold;">
                    
                    <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Kitchen Notes</label>
                    <textarea id="oi-edit-note" style="width:100%; box-sizing:border-box; padding:10px; border-radius:8px; border:1px solid #ccc; font-family:inherit; min-height:80px; resize:vertical;">${item.item_notes || ''}</textarea>
                </div>
            `,
            focusConfirm: false, showCancelButton: true, confirmButtonText: 'Update', confirmButtonColor: '#6B4226', width: 400,
            preConfirm: () => {
                const name = document.getElementById('oi-edit-name').value;
                const price = parseFloat(document.getElementById('oi-edit-price').value);
                const note = document.getElementById('oi-edit-note').value;
                if (!name || isNaN(price)) { Swal.showValidationMessage('Valid name and price required'); return false; }
                return { name, price, note };
            }
        });

        if (formValues) {
            item.name = '⭐ ' + formValues.name; // Put the star back!
            item.price = formValues.price;
            item.item_notes = formValues.note || null;
            renderCart();
        }
        return; // Stop here so it doesn't run the normal code!
    }

    // --- STANDARD DATABASE ITEM LOGIC BELOW ---
    const p = state.products.find(x => x.id == item.id);
    if (!p) return;
    
    let selectedMods = item.modifiers ? item.modifiers.map(m => m.id) : [];

    const options = await window.selectProductOptions(p, item.variation_id, selectedMods, item.item_notes, true);
    if (options.canceled) return; 

    if (options.variation_id) {
        item.variation_id = options.variation_id;
        item.variation_name = options.variation_name;
        item.name = `${p.name} (${options.variation_name})`;
        item.price = options.price;
    }
    item.modifiers = options.modifiers;
    item.item_notes = options.item_notes;

    const modKey = item.modifiers.map(m => m.id).sort().join(',');
    const uniqueKey = `${item.id}-${item.variation_id}-${item.price}-${modKey}-${item.item_notes || ''}`;
    const dupIdx = state.cart.findIndex((i, index) => index !== idx && `${i.id}-${i.variation_id}-${i.price}-${i.modifiers.map(m=>m.id).sort().join(',')}-${i.item_notes || ''}` === uniqueKey);
    
    if (dupIdx > -1) { 
        state.cart[dupIdx].qty += item.qty; 
        state.cart.splice(idx, 1); 
    }
    renderCart();
};

window.promptItemDiscount = async function(idx) {
    const item = state.cart[idx];
    const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
    const lineTotalRaw = (item.price + mCost) * item.qty; // The max allowed discount

    const { value: formValues } = await Swal.fire({
        title: 'Item Discount',
        html: `
            <div style="font-weight:bold; color:var(--text-muted); margin-bottom:15px;">Max Allowed: ₱${lineTotalRaw.toFixed(2)}</div>
            <select id="idisc-type" class="search-bar" style="margin-bottom:10px; font-weight:bold; text-align:center;">
                <option value="amount" ${item.discount_type === 'amount' ? 'selected' : ''}>Flat Amount (₱)</option>
                <option value="percent" ${item.discount_type === 'percent' ? 'selected' : ''}>Percentage (%)</option>
            </select>
            <input type="number" id="idisc-val" class="swal2-input" placeholder="Value" step="0.01" value="${item.discount_val || ''}">
            <input type="text" id="idisc-note" class="swal2-input" placeholder="Reason (e.g. Spilled, VIP)" value="${item.discount_note || ''}">
            <button class="btn danger" style="width:100%; margin-top:10px;" onclick="document.getElementById('idisc-val').value=''; document.getElementById('idisc-note').value='';">Clear Discount</button>
        `,
        focusConfirm: false,
        preConfirm: () => {
            const type = document.getElementById('idisc-type').value;
            const val = parseFloat(document.getElementById('idisc-val').value) || 0;
            const note = document.getElementById('idisc-note').value;

            let calculatedAmount = 0;
            if (val > 0) {
                if (type === 'percent') {
                    if (val > 100) return Swal.showValidationMessage('Cannot exceed 100%');
                    calculatedAmount = lineTotalRaw * (val / 100);
                } else {
                    if (val > lineTotalRaw) return Swal.showValidationMessage('Discount cannot exceed item price (₱' + lineTotalRaw.toFixed(2) + ')');
                    calculatedAmount = val;
                }
            }
            return { type, val, amount: calculatedAmount, note };
        }
    });

    if (formValues) {
        state.cart[idx].discount_type = formValues.type;
        state.cart[idx].discount_val = formValues.val;
        state.cart[idx].discount_amount = formValues.amount; // The actual ₱ deducted
        state.cart[idx].discount_note = formValues.note;
        renderCart();
    }
};

window.clearCart = async function() {
    if (state.cart.length === 0 && !state.activeOrderId) return Swal.fire('Empty', 'Your cart is already empty.', 'info');

    if (state.activeOrderId && state.activeOrderId !== 'new') {
        // SECURITY: Order is saved! Force PIN and Reason to kill the table.
        const { value: formValues } = await Swal.fire({
            title: 'Void Entire Order?',
            html: `
                <div style="font-size:0.9rem; color:var(--danger); margin-bottom:15px; font-weight:bold;">
                    This table is active. To void the whole ticket, please authorize.
                </div>
                <input type="text" id="clear-reason" class="swal2-input" placeholder="Reason (e.g. Walk-out)">
                <input type="password" id="clear-pin" class="swal2-input" placeholder="Manager PIN" inputmode="numeric">
            `,
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Void Table', confirmButtonColor: '#d33',
            preConfirm: () => {
                const reason = document.getElementById('clear-reason').value;
                const pin = document.getElementById('clear-pin').value;
                if (!reason || !pin) { Swal.showValidationMessage('Reason and Manager PIN are required!'); return false; }
                return { reason, pin };
            }
        });

        if (formValues) {
            Swal.fire({title:'Voiding...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
            try {
                const res = await fetch('../api/clear_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                    body: JSON.stringify({order_id: state.activeOrderId, reason: formValues.reason, pin: formValues.pin})
                });
                const data = await res.json();
                if (data.success) {
                    Swal.fire('Voided!', 'Table has been cleared.', 'success');
                    state.cart = []; state.activeTableId = null; state.activeOrderId = null; state.grand_total = 0;
                    document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
                    renderCart();
                    if (typeof showTablePopup === 'function' && state.mode !== 'takeout') showTablePopup();
                } else { Swal.fire('Error', data.error, 'error'); }
            } catch (e) { Swal.fire('Error', 'Connection failed', 'error'); }
        }
    } else {
        // Unsaved order: Allow normal clearing
        Swal.fire({ title: 'Clear Cart?', text: 'Remove all unsaved items?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' }).then((res) => {
            if (res.isConfirmed) {
                state.cart = []; state.activeTableId = null; state.activeOrderId = null; state.grand_total = 0;
                document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
                renderCart();
                if (typeof showTablePopup === 'function' && state.mode !== 'takeout') showTablePopup();
            }
        });
    }
};

window.applyDiscountPopup = async function() {
    if(state.cart.length === 0) return Swal.fire('Empty', 'Add items first', 'warning');
    let html = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:15px;">';
    
    state.discounts.forEach(d => { 
        let label = d.name;
        if (d.type === 'percent') label += ` (${parseFloat(d.value)}%)`;
        else label += ` (₱${parseFloat(d.value)})`;

        let targetText = '';
        if (d.target_type === 'all') targetText = 'Whole Bill';
        else if (d.target_type === 'highest') targetText = 'Highest Item (SC/PWD)';
        else if (d.target_type === 'food') targetText = 'Food Only';
        else if (d.target_type === 'drink') targetText = 'Drinks Only';
        else targetText = 'Specific Categories';

        html += `
            <button class="btn secondary" style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:12px; line-height:1.2;" onclick="selectDiscount(${d.id})">
                <span style="font-weight:bold; font-size:1rem; color:var(--brand-dark);">${label}</span>
                <span style="font-size:0.75rem; color:gray; margin-top:4px;">Applies to: ${targetText}</span>
            </button>
        `; 
    });
    html += `</div>`;

    // 🌟 NEW: THE ROUNDING / EXACT TOTAL BUTTON 🌟
    html += `<button class="btn" style="width:100%; background:#f59e0b; color:white; border:none; margin-bottom:10px;" onclick="exactTotalDiscountPopup()">🎯 Set Exact Total (Round Down)</button>`;
    
    html += `<button class="btn" style="width:100%; background:var(--blue); margin-bottom:10px;" onclick="customOrderDiscount()">🌟 Custom Target Discount</button>`;
    html += `<button class="btn danger" style="width:100%;" onclick="selectDiscount(0)">Remove All Discounts</button>`;
    
    Swal.fire({ title: 'Select Discount', html: html, showConfirmButton: false, showCancelButton: true });
};

window.exactTotalDiscountPopup = async function() {
    Swal.close();
    
    // 🌟 THE FIX: Use the Grand Total, which ALREADY includes the Senior Discount!
    const currentTotal = state.grand_total;

    if (currentTotal <= 0) return Swal.fire('Error', 'Bill is already zero.', 'error');

    const { value: targetTotal } = await Swal.fire({
        title: '🎯 Round Down Bill',
        html: `
            <div style="font-size:0.9rem; color:gray; margin-bottom:15px;">The current total (after all discounts) is <b>₱${currentTotal.toFixed(2)}</b>. What exact amount do you want to charge?</div>
            <input type="number" id="et-val" class="search-bar" placeholder="e.g. 1500" step="0.01" style="font-size:2rem; text-align:center; height:70px; font-weight:bold; color:var(--brand-dark);">
        `,
        focusConfirm: false, showCancelButton: true, confirmButtonText: 'Apply Adjustment', confirmButtonColor: '#6B4226',
        preConfirm: () => {
            const val = parseFloat(document.getElementById('et-val').value);
            if (isNaN(val) || val < 0) {
                Swal.showValidationMessage('Please enter a valid amount.');
                return false;
            }
            if (val >= currentTotal) {
                Swal.showValidationMessage('Amount must be lower than the current total (₱' + currentTotal.toFixed(2) + ')');
                return false;
            }
            return val;
        }
    });

    if (targetTotal !== undefined) {
        const discountToApply = currentTotal - targetTotal;
        
        // WE DO NOT NULLIFY state.discount_id ANYMORE! Let it stack!
        state.custom_discount = { 
            is_active: true, 
            type: 'amount', 
            val: discountToApply, 
            target: 'all', 
            note: 'Round Off' 
        };
        
        Swal.fire({title:'Adjusting...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
        await saveOrder(true);
        Swal.fire({title:'Applied', text: 'Bill rounded down to ₱' + targetTotal.toFixed(2), icon:'success', timer: 1000, showConfirmButton: false});
    }
};

window.customOrderDiscount = async function() {
    Swal.close();
    let catChecks = state.categories.map(c => `
        <label style="display:block; padding:5px 0;">
            <input type="checkbox" class="cd-cat-cb" value="${c.id}"> ${c.name}
        </label>
    `).join('');

    const { value: formValues } = await Swal.fire({
        title: 'Custom Discount',
        html: `
            <select id="cd-type" class="search-bar" style="margin-bottom:10px; font-weight:bold;">
                <option value="percent">Percentage (%)</option>
                <option value="amount">Flat Amount (₱)</option>
            </select>
            <input type="number" id="cd-val" class="search-bar" placeholder="Enter Value (e.g. 10)" step="0.01" style="margin-bottom:10px;">
            <select id="cd-target" class="search-bar" style="margin-bottom:10px; font-weight:bold;" onchange="document.getElementById('cat-list').style.display = this.value === 'custom' ? 'block' : 'none'">
                <option value="all">Whole Bill (Excl. Alcohol)</option>
                <option value="food">All Food Only</option>
                <option value="drink">All Drinks (Excl. Alcohol)</option>
                <option value="custom">Specific Categories (Custom)...</option>
            </select>
            <div id="cat-list" style="display:none; text-align:left; background:#f9fafb; padding:10px; border:1px solid #ddd; border-radius:8px; max-height:150px; overflow-y:auto; margin-bottom:10px;">
                ${catChecks}
            </div>
            <input type="text" id="cd-note" class="search-bar" placeholder="Reason (e.g. Employee, Vip)" style="margin-bottom:0;">
        `,
        focusConfirm: false, showCancelButton: true, confirmButtonText: 'Apply Discount',
        preConfirm: () => {
            const val = parseFloat(document.getElementById('cd-val').value);
            if (!val || val <= 0) { Swal.showValidationMessage('Enter a valid amount'); return false; }
            const target = document.getElementById('cd-target').value;
            let targetCats = [];
            if (target === 'custom') { // FIX: Check for 'custom' here!
                document.querySelectorAll('.cd-cat-cb:checked').forEach(cb => targetCats.push(parseInt(cb.value)));
                if (targetCats.length === 0) { Swal.showValidationMessage('Select at least one category!'); return false; }
            }
            return { is_active: true, type: document.getElementById('cd-type').value, val: val, target: target, target_cats: targetCats, note: document.getElementById('cd-note').value || 'Custom Discount' };
        }
    });
    
    if (formValues) {
        state.discount_id = null; state.senior_details = []; state.custom_discount = formValues;
        Swal.fire({title:'Calculating...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
        await saveOrder(true);
        Swal.fire({title:'Applied', text: 'Discount calculated successfully.', icon:'success', timer: 1000, showConfirmButton: false});
    }
};

window.setSeniorType = function(btn, type) {
    const row = btn.closest('.s-row');
    row.querySelectorAll('.btn-stype').forEach(b => { b.style.background = '#f3f4f6'; b.style.color = 'gray'; b.style.borderColor = '#ccc'; });
    btn.style.background = type === 'SC' ? 'var(--brand)' : '#005ce6';
    btn.style.color = 'white'; btn.style.borderColor = type === 'SC' ? 'var(--brand)' : '#005ce6';
    row.querySelector('.s-type').value = type;
};

window.selectDiscount = async function(discId) {
    if(discId === 0) {
        state.discount_id = null; state.discount_note = ''; state.senior_details = []; state.discount_amount = 0; state.custom_discount = { is_active: false };
        Swal.close(); 
        Swal.fire({title:'Clearing...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
        await saveOrder(true); 
        return Swal.fire({title: 'Removed', text: 'Discount cleared', icon: 'success', timer: 1000, showConfirmButton: false});
    }

    const d = state.discounts.find(x => x.id == discId);
    state.discount_id = d.id;
    state.custom_discount = { is_active: false };

    if (d.name.toLowerCase().includes('senior') || d.target_type === 'highest') {
        const uniqueId = Date.now();
        const personRowHTML = `
            <div class="s-row" style="background:#f9fafb; padding:10px; border-radius:8px; margin-bottom:10px; border:1px solid #ddd; text-align:left;">
                <div class="radio-toggle">
                    <input type="radio" id="sc_${uniqueId}" name="stype_${uniqueId}" value="SC" class="s-type" checked>
                    <label for="sc_${uniqueId}" class="sc-label">Senior (SC)</label>
                    <input type="radio" id="pwd_${uniqueId}" name="stype_${uniqueId}" value="PWD" class="s-type">
                    <label for="pwd_${uniqueId}" class="pwd-label">PWD</label>
                </div>
                <input type="text" class="search-bar s-name" placeholder="Full Name" style="margin-bottom:5px; padding:10px;">
                <input type="text" class="search-bar s-id" placeholder="ID Number" style="margin-bottom:5px; padding:10px;">
                <input type="text" class="search-bar s-address" placeholder="Address (Optional)" style="margin-bottom:0; padding:10px;">
            </div>
        `;

        const { value: details } = await Swal.fire({
            title: 'Senior/PWD Details',
            html: `
                <div id="snr-box" style="text-align:left; max-height:300px; overflow-y:auto; padding-bottom:5px;">
                    ${personRowHTML}
                </div>
                <button type="button" class="btn secondary" onclick="addSeniorRow()" style="width:100%; margin-top:10px;">+ Add Another Person</button>
            `,
            didOpen: () => {
                window.addSeniorRow = () => { 
                    const uId = Date.now();
                    document.getElementById('snr-box').insertAdjacentHTML('beforeend', `
                        <div class="s-row" style="background:#f9fafb; padding:10px; border-radius:8px; margin-bottom:10px; border:1px solid #ddd; text-align:left;">
                            <div class="radio-toggle">
                                <input type="radio" id="sc_${uId}" name="stype_${uId}" value="SC" class="s-type" checked>
                                <label for="sc_${uId}" class="sc-label">Senior (SC)</label>
                                <input type="radio" id="pwd_${uId}" name="stype_${uId}" value="PWD" class="s-type">
                                <label for="pwd_${uId}" class="pwd-label">PWD</label>
                            </div>
                            <input type="text" class="search-bar s-name" placeholder="Full Name" style="margin-bottom:5px; padding:10px;">
                            <input type="text" class="search-bar s-id" placeholder="ID Number" style="margin-bottom:5px; padding:10px;">
                            <input type="text" class="search-bar s-address" placeholder="Address (Optional)" style="margin-bottom:0; padding:10px;">
                        </div>
                    `); 
                };
            },
            preConfirm: () => {
                let data = [];
                for (let r of document.querySelectorAll('.s-row')) {
                    const id = r.querySelector('.s-id').value;
                    const name = r.querySelector('.s-name').value;
                    const address = r.querySelector('.s-address').value; // Grab the address!
                    const type = r.querySelector('.s-type:checked').value;
                    if (!id || !name) { Swal.showValidationMessage('Name and ID are both required!'); return false; }
                    data.push({ type: type, id: id, name: name, address: address });
                }
                return data;
            }
        });
        if (details) {
            state.senior_details = details; state.discount_note = `SC/PWD (${details.length} Pax)`;
            Swal.fire({title:'Calculating...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
            await saveOrder(true);
            // ==============================================================================
            // FIX #1: CLOSE THE LOADING SPINNER AFTER SAVING THE SC/PWD DETAILS!
            // ==============================================================================
            Swal.fire({title: 'Applied', text: 'Discount added successfully.', icon: 'success', timer: 1000, showConfirmButton: false});
        } else { state.discount_id = null; }
    } else {
        state.discount_note = d.name;
        Swal.fire({title:'Calculating...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
        await saveOrder(true); 
        Swal.fire({title: 'Applied', text: 'Discount added successfully.', icon: 'success', timer: 1000, showConfirmButton: false});
    }
};

function syncOrderState(d) {
    state.activeOrderId = d.order_id || (d.order_info ? d.order_info.id : null);
    
    state.cart = d.items;
    const totalItemDisc = d.items.reduce((sum, item) => sum + (parseFloat(item.discount_amount) || 0), 0);
    let globalDisc = (parseFloat(d.order_info.discount_total) || 0) - totalItemDisc;
    if (globalDisc < 0.01) globalDisc = 0; 
    
    state.discount_amount = globalDisc;
    
    const dbNote = d.order_info.discount_note || '';
    if (dbNote.includes('Custom:')) {
        // If the tablet doesn't currently remember the custom discount, try to reconstruct it from the DB
        if (!state.custom_discount || !state.custom_discount.is_active) {
            let extractedNote = dbNote;
            if (dbNote.includes(' + Custom: ')) {
                extractedNote = dbNote.split(' + Custom: ')[1];
            } else {
                extractedNote = dbNote.replace('Custom: ', '');
            }
            state.custom_discount = { is_active: true, type: 'amount', val: globalDisc, target: 'all', note: extractedNote };
        }
        // If the tablet ALREADY knows about it, do nothing. Let it keep its memory!
    } else {
        state.custom_discount = { is_active: false, type: 'percent', val: 0, target: 'all', note: '' };
    }
    
    state.discount_note = d.order_info.discount_note || '';
    state.discount_id = d.order_info.discount_id || null;
    state.amount_paid = parseFloat(d.order_info.amount_paid) || 0;
    state.customer_name = d.order_info.customer_name || null;
    
    // ==============================================================================
    // FIX #3: SYNC THE DATABASE SC/PWD ARRAY BACK INTO THE POS JAVASCRIPT MEMORY!
    // ==============================================================================
    state.senior_details = d.order_info.senior_details || [];
}

let isSaving = false;
window.saveOrder = async function(silent = false, voidReason = null) { // ADDED voidReason PARAMETER
    if (isSaving) return;
    if (state.cart.length === 0) return Swal.fire('Empty', 'Add items first', 'warning');
    isSaving = true;

    const response = await fetch('../api/save_order.php', {
        method: 'POST', 
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken() 
        },
        body: JSON.stringify({
            items: state.cart, table_id: state.activeTableId, order_id: state.activeOrderId === 'new' ? null : state.activeOrderId,
            order_type: state.mode, discount_id: state.discount_id, discount_note: state.discount_note, senior_details: state.senior_details,
            custom_discount: state.custom_discount, customer_name: state.customer_name,
            void_reason: voidReason // ADDED: Sent securely to PHP
        })
    });
    
    const result = await response.json();
    
    if (result.success) {
        state.activeOrderId = result.order_id;
        if(state.activeOrderId) {
            const r = await fetch(`../api/get_active_order.php?order_id=${state.activeOrderId}`);
            const d = await r.json();
            if(d.success) { syncOrderState(d); renderCart(); }
        }
        if(!silent) Swal.fire({ icon: 'success', title: 'Order Saved', timer: 1000, showConfirmButton: false });
        
    } else if (result.error === 'Unauthorized') {
        // 🚨 MAGIC FIX: RESCUE CART LOGIC (Session died while tablet was asleep)
        const { value: pin } = await Swal.fire({
            title: 'Session Expired',
            text: 'Please enter your PIN to resume saving this order:',
            input: 'password',
            inputAttributes: { inputmode: 'numeric', pattern: '[0-9]*' },
            showCancelButton: true,
            confirmButtonColor: '#6B4226'
        });
        
        if (pin) {
            Swal.fire({title:'Resuming...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
            const loginRes = await fetch('../api/auth_login.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ passcode: pin })
            });
            const loginData = await loginRes.json();
            
            if (loginData.success) {
                // Inject the new security token into the page and re-run the save!
                document.querySelector('meta[name="csrf-token"]').setAttribute('content', loginData.csrf_token);
                return saveOrder(silent); 
            } else {
                Swal.fire('Error', 'Invalid PIN. Order not saved.', 'error');
            }
        }
    } else {
        Swal.fire('Error', result.error, 'error');
    }
    isSaving = false;
};

window.loadTakeout = async function(id) { 
    state.activeOrderId = id;
    Swal.close();
    const r = await fetch(`../api/get_active_order.php?order_id=${id}`);
    const d = await r.json();
    if(d.success) {
        syncOrderState(d);
        document.getElementById('tableName').innerText = state.customer_name ? ('Takeout: ' + state.customer_name) : ('Takeout #' + id);
        renderCart();
    }
};

window.pickTable = async function(id, num, status) { 
    state.activeTableId = id;
    document.getElementById('tableName').innerText = 'Table ' + num;
    state.customer_name = null;
    Swal.close();
    
    if(status === 'occupied') {
        const r = await fetch(`../api/get_active_order.php?table_id=${id}`);
        const d = await r.json();
        
        if(d.multiple) {
            // MULTIPLE CHECKS POPUP (Now with Fast Item Summary!)
            let html = '<div style="display:flex; flex-direction:column; gap:10px; max-height:400px; overflow-y:auto; padding-right:5px;">';
            d.orders.forEach((o, index) => {
                let cName = o.customer_name ? o.customer_name : `Check ${index + 1}`;
                let summary = o.item_summary ? o.item_summary : 'No items';
                
                html += `
                <button class="btn secondary" style="padding:15px; text-align:left; display:flex; flex-direction:column; gap:8px; border:1px solid #ccc; background:#fff;" onclick="loadSubCheck(${o.id}, '${cName}')">
                    <div style="display:flex; justify-content:space-between; width:100%; align-items:center;">
                        <span style="font-weight:900; font-size:1.1rem; color:var(--text-main);">🧾 ${cName}</span>
                        <span style="font-weight:900; color:var(--brand); font-size:1.2rem;">₱${parseFloat(o.grand_total).toFixed(2)}</span>
                    </div>
                    <div style="font-size:0.85rem; color:gray; white-space:normal; line-height:1.4;">
                        ${summary}
                    </div>
                </button>`;
            });
            html += `<button class="btn" style="padding:15px; margin-top:5px; background:#2e7d32;" onclick="createNewSubCheck()">+ Create New Check Here</button>`;
            html += '</div>';
            
            Swal.fire({ title: 'Table ' + num + ' (Sub-Checks)', html: html, showConfirmButton: false, showCancelButton: true });
        } else if(d.success) { 
            syncOrderState(d); renderCart(); 
        }
    } else { 
        state.activeOrderId = null; 
        state.cart = []; state.discount_amount = 0; state.discount_note = ''; state.discount_id = null; state.amount_paid = 0;
        state.custom_discount = { is_active: false };
        renderCart();
    }
};

window.loadSubCheck = async function(orderId, cName) {
    Swal.fire({title:'Loading...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    const r = await fetch(`../api/get_active_order.php?order_id=${orderId}`);
    const d = await r.json();
    if(d.success) { 
        syncOrderState(d); 
        document.getElementById('tableName').innerText = document.getElementById('tableName').innerText.split(' - ')[0] + ' - ' + cName;
        renderCart(); 
        Swal.close();
    }
};

window.createNewSubCheck = function() {
    state.activeOrderId = 'new';
    state.cart = []; state.discount_id = null; state.discount_amount = 0; state.discount_note = ''; state.senior_details = []; state.amount_paid = 0;
    state.custom_discount = { is_active: false };
    Swal.close();
    renderCart();
    Swal.fire('New Check Started', 'Add items and hit save to generate a secondary check on this table.', 'info');
};

window.splitBillByItem = async function(balance) {
    if (state.cart.length === 0) return;
    
    let checklistHtml = `<div style="text-align:left; max-height:250px; overflow-y:auto; border:1px solid #eee; padding:10px; border-radius:8px;">`;
    state.cart.forEach((item, idx) => {
        const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
        const lineTotal = ((item.price + mCost) * item.qty) - (parseFloat(item.discount_amount) || 0);
        checklistHtml += `
            <label style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px dashed #eee; cursor:pointer;">
                <span style="font-size:0.95rem;"><input type="checkbox" class="split-cb" data-name="${item.qty}x ${item.name}" value="${lineTotal}"> ${item.qty}x ${item.name}</span>
                <span style="font-weight:bold; color:var(--brand);">₱${lineTotal.toFixed(2)}</span>
            </label>`;
    });
    checklistHtml += `</div>`;

    const { value: splitResult } = await Swal.fire({
        title: 'Select Items to Pay', html: checklistHtml, showCancelButton: true, confirmButtonText: 'Use Selected Total', cancelButtonText: 'Back', confirmButtonColor: 'var(--brand)',
        preConfirm: () => {
            let sum = 0; let selectedNames = [];
            document.querySelectorAll('.split-cb:checked').forEach(cb => { sum += parseFloat(cb.value); selectedNames.push(cb.dataset.name); });
            if (sum <= 0) { Swal.showValidationMessage('Select at least one item'); return false; }
            return { sum, selectedNames };
        }
    });

    if (splitResult) window.checkout(splitResult.sum, splitResult.selectedNames);
    else window.checkout(); 
};

window.setPayMethod = function(method) {
    document.getElementById('pay-method').value = method;
    const isCash = method === 'cash';
    
    // Toggle Button Styles
    const btnCash = document.getElementById('btn-cash');
    const btnGcash = document.getElementById('btn-gcash');
    
    if (isCash) {
        btnCash.style.background = 'var(--brand)'; btnCash.style.color = 'white'; btnCash.style.borderColor = 'var(--brand)';
        btnGcash.style.background = 'white'; btnGcash.style.color = 'gray'; btnGcash.style.borderColor = '#ccc';
    } else {
        btnGcash.style.background = '#005ce6'; btnGcash.style.color = 'white'; btnGcash.style.borderColor = '#005ce6';
        btnCash.style.background = 'white'; btnCash.style.color = 'gray'; btnCash.style.borderColor = '#ccc';
        
        // Auto-fill exact balance for GCash
        const balance = parseFloat(document.getElementById('co-balance-raw').value) || 0;
        document.getElementById('pay-amount').value = balance.toFixed(2);
    }

    // Hide/Show Cash Pad and Change display
    document.getElementById('cash-pad').style.display = isCash ? 'grid' : 'none';
    document.getElementById('change-row').style.visibility = isCash ? 'visible' : 'hidden';
    
    // Trigger input event to update display
    document.getElementById('pay-amount').dispatchEvent(new Event('input'));
};

window.addTendered = function(amount) {
    const input = document.getElementById('pay-amount');
    const current = parseFloat(input.value) || 0;
    input.value = (current + amount).toFixed(2);
    input.dispatchEvent(new Event('input', { bubbles: true }));
};

window.checkout = async function(prefillAmount = null, selectedItems = null) {
    if (state.cart.length === 0) return Swal.fire('Empty', 'Nothing to charge!', 'warning');
    if (prefillAmount === null) { await window.saveOrder(true); }
    if (!state.activeOrderId) return; 

    const balance = Math.round((state.grand_total - state.amount_paid) * 100) / 100;
    
    if (balance <= 0) {
        Swal.fire('Paid', 'This order is already fully paid.', 'info');
        state.cart = []; state.discount_id = null; state.discount_amount = 0; state.activeTableId = null; state.activeOrderId = null;
        renderCart(); return;
    }

    const denominations = [20, 50, 100, 200, 500, 1000];
    const initialInput = prefillAmount ? prefillAmount.toFixed(2) : '';

    const { value: formValues } = await Swal.fire({
        title: 'Finalize Payment',
        width: '800px', 
        html: `
            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 20px; text-align: left;">
                
                <div style="border-right: 1px solid #eee; padding-right: 20px;">
                    <div style="background:#f9fafb; padding:15px; border-radius:10px; border:1px solid #eee; margin-bottom:15px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px;"><span>Total Bill:</span> <span>₱${state.grand_total.toFixed(2)}</span></div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px; color:var(--text-muted); font-size:0.9rem;"><span>Already Paid:</span> <span>₱${state.amount_paid.toFixed(2)}</span></div>
                        <hr style="border:0; border-top:1px dashed #ccc; margin:10px 0;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-weight:bold;">Balance Due:</span> 
                            <span style="font-weight:900; color:var(--danger); font-size:1.8rem;">₱${balance.toFixed(2)}</span>
                        </div>
                        <input type="hidden" id="co-balance-raw" value="${balance}">
                        <div id="change-row" style="display:flex; justify-content:space-between; color:var(--success); margin-top:10px; font-weight:bold;">
                            <span>Change:</span> <span id="co-change" style="font-size:1.2rem;">₱0.00</span>
                        </div>
                    </div>

                    ${selectedItems ? `<div style="font-size:0.8rem; background:#fff3e0; padding:8px; border-radius:6px; margin-bottom:15px; border:1px solid #ffcc80;"><b>Splitting:</b> ${selectedItems.join(', ')}</div>` : ''}

                    <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Payment Method</label>
                    <div style="display:flex; gap:10px; margin-top:8px; margin-bottom:20px;">
                        <button type="button" id="btn-cash" style="flex:1; padding:15px; border-radius:8px; border:2px solid var(--brand); background:var(--brand); color:white; font-weight:bold; cursor:pointer;" onclick="setPayMethod('cash')">💵 CASH</button>
                        <button type="button" id="btn-gcash" style="flex:1; padding:15px; border-radius:8px; border:2px solid #ccc; background:white; color:gray; font-weight:bold; cursor:pointer;" onclick="setPayMethod('gcash')">📱 GCASH</button>
                    </div>
                    
                    <button class="btn secondary" style="width:100%; border-style:dashed;" onclick="splitBillByItem(${balance})">✂️ Split by Specific Items</button>
                </div>

                <div>
                    <label style="font-weight:bold; font-size:0.85rem; color:var(--brand); text-transform:uppercase;">Staff Tip (Optional)</label>
                    <input type="number" id="pay-tip" class="search-bar" placeholder="0.00" step="0.01" 
                           style="font-size:1.5rem; text-align:center; height:50px; width:100%; margin:5px 0 15px 0; border:1px solid var(--border); color:var(--brand-dark);">

                    <label style="font-weight:bold; font-size:0.85rem; color:var(--text-muted); text-transform:uppercase;">Amount Tendered</label>
                    <input type="number" id="pay-amount" class="search-bar" value="${initialInput}" placeholder="0.00" step="0.01" 
                           style="font-size:2.5rem; text-align:center; height:80px; width:100%; margin:5px 0 10px 0; border:2px solid var(--brand); font-weight:900; color:var(--brand-dark);">
                    
                    <div id="cash-pad" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px;">
                        <button class="btn secondary" style="height:55px; font-weight:900;" onclick="addTendered(${prefillAmount || balance})">EXACT</button>
                        ${denominations.map(amt => `<button class="btn secondary" style="height:55px; font-weight:bold;" onclick="addTendered(${amt})">₱${amt}</button>`).join('')}
                        <button class="btn danger" style="height:55px; font-weight:bold;" onclick="document.getElementById('pay-amount').value=''; document.getElementById('pay-amount').dispatchEvent(new Event('input'));">CLEAR</button>
                    </div>
                </div>
            </div>
            <input type="hidden" id="pay-method" value="cash">
        `,
        showCancelButton: true, confirmButtonText: 'PROCESS PAYMENT', confirmButtonColor: '#2e7d32',
        didOpen: () => {
            const amtInput = document.getElementById('pay-amount');
            const tipInput = document.getElementById('pay-tip'); // Grab the tip input
            
            const updateChange = () => {
                const targetDue = prefillAmount || balance;
                const tendered = parseFloat(amtInput.value) || 0;
                const tip = parseFloat(tipInput.value) || 0; // Include tip in math
                
                // MATH: Change = Tendered - Bill - Tip
                const change = Math.round((tendered - targetDue - tip) * 100) / 100;
                
                const changeEl = document.getElementById('co-change');
                if (change >= 0) { 
                    changeEl.innerText = '₱' + change.toFixed(2); 
                    changeEl.style.color = 'var(--success)';
                } else { 
                    changeEl.innerText = 'Partial (Rem: ₱' + Math.abs(change).toFixed(2) + ')'; 
                    changeEl.style.color = '#e65100'; 
                }
            };
            
            // Listen to BOTH inputs so change calculates dynamically!
            amtInput.addEventListener('input', updateChange);
            tipInput.addEventListener('input', updateChange);
            if (prefillAmount) updateChange();
        },
        preConfirm: () => {
            const method = document.getElementById('pay-method').value;
            const amount = parseFloat(document.getElementById('pay-amount').value);
            const tip = parseFloat(document.getElementById('pay-tip').value) || 0; // Get Tip
            
            if(isNaN(amount) || amount <= 0) { Swal.showValidationMessage('Enter a valid tendered amount'); return false; }
            if (amount < (balance + tip)) { Swal.showValidationMessage('Tendered amount is too low to cover bill + tip!'); return false; }
            
            return { method, amount, tip }; // Pass tip to payload
        }
    });

    if(formValues) {
        const res = await fetch('../api/checkout.php', {
            method: 'POST', 
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ 
                order_id: state.activeOrderId, 
                method: formValues.method, 
                amount: formValues.amount, 
                tip: formValues.tip, // SEND TIP TO SERVER
                customer_name: state.customer_name 
            })
        });
        const data = await res.json();
        if(data.success) {
            if (data.is_fully_paid) {
                const paidOrderId = state.activeOrderId;
                Swal.fire({
                    title: 'Fully Paid!', text: 'Change: ₱' + data.change.toFixed(2), icon: 'success', 
                    showCancelButton: true, confirmButtonText: '🖨️ Print Receipt', cancelButtonText: 'Done', confirmButtonColor: '#2e7d32'
                }).then((res) => { if (res.isConfirmed) fetch(`../api/print_order.php?order_id=${paidOrderId}&type=receipt`); });

                state.cart = []; state.discount_id = null; state.discount_amount = 0; state.activeTableId = null; state.activeOrderId = null;
                document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
                renderCart();
            } else {
                Swal.fire({title: 'Partial Payment Saved', icon: 'info', confirmButtonColor: '#6B4226'});
                state.amount_paid += formValues.amount; 
                renderCart();
            }
        } else { Swal.fire('Error', data.error, 'error'); }
    }
};

window.printOrder = async function(type, event) {
    if (!state.activeOrderId) return Swal.fire('Error', 'Please save the order first before printing.', 'warning');
    const btn = event ? event.currentTarget : null;
    let oldText = "";
    if (btn) { oldText = btn.innerHTML; btn.innerHTML = "Printing..."; btn.disabled = true; }
    try {
        const r = await fetch(`../api/print_order.php?order_id=${state.activeOrderId}&type=${type}`);
        const d = await r.json();
        if(d.success) {
            if(d.errors && d.errors.length > 0) Swal.fire('Warning', 'Printed with issues:\n' + d.errors.join('\n'), 'warning');
            else Swal.fire({title: 'Printed successfully', icon: 'success', timer: 1000, showConfirmButton: false});
        } else { Swal.fire('Print Failed', d.message || d.error, 'error'); }
    } catch(e) { Swal.fire('Error', 'Could not reach printer service.', 'error'); }
    if (btn) { btn.innerHTML = oldText; btn.disabled = false; }
};
window.transferTablePopup = async function() {
    if (!state.activeOrderId || state.activeOrderId === 'new') return;
    
    Swal.fire({title:'Loading tables...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    const r = await fetch('../api/get_tables.php');
    const tables = await r.json();
    
    let html = '<div style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px;">';
    let hasAvailable = false;
    tables.forEach(t => {
        // Only show tables that are empty!
        if (t.status === 'available') {
            hasAvailable = true;
            html += `<div style="padding:15px 5px; border-radius:8px; cursor:pointer; font-weight:bold; background:#f0fdf4; border:1px solid #bbf7d0; color:var(--brand-dark);" onclick="executeTransfer(${t.id}, '${t.table_number}')">${t.table_number}</div>`;
        }
    });
    html += '</div>';

    if (!hasAvailable) return Swal.fire('No Tables', 'All other tables are currently occupied!', 'info');

    Swal.fire({ title: 'Move to which table?', html: html, showConfirmButton: false, showCancelButton: true });
};

window.executeTransfer = async function(newTableId, newTableNum) {
    Swal.fire({title:'Moving...', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
    try {
        const res = await fetch('../api/transfer_table.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
            body: JSON.stringify({ order_id: state.activeOrderId, new_table_id: newTableId })
        });
        const data = await res.json();
        if (data.success) {
            state.activeTableId = newTableId;
            document.getElementById('tableName').innerText = data.new_table_name;
            Swal.fire({icon: 'success', title: 'Moved successfully!', timer: 1000, showConfirmButton: false});
        } else {
            Swal.fire('Error', data.error, 'error');
        }
    } catch (e) {
        Swal.fire('Error', 'Failed to transfer table.', 'error');
    }
};
function toggleCartDrawer() {
            const panel = document.getElementById('cartBottomPanel');
            const btn = document.getElementById('drawerBtn');
            panel.classList.toggle('open');
            
            if (panel.classList.contains('open')) {
                btn.style.background = 'var(--brand)';
                btn.style.color = 'white';
                btn.innerHTML = '▼'; // Changes to a close arrow
            } else {
                btn.style.background = 'white';
                btn.style.color = 'var(--text-main)';
                btn.innerHTML = '⚙️'; // Changes back to the gear
            }
        }
// ============================================================================
// ORDER SPLITTING LOGIC (Generates separate physical receipts)
// ============================================================================
// ============================================================================
// TRUE SUB-CHECK SPLITTING (Keeps items as Dine-In on the exact same table!)
// ============================================================================
window.splitOrderPopup = async function() {
    if (!state.activeOrderId || state.activeOrderId === 'new') return Swal.fire('Save First', 'Please save the order before splitting.', 'warning');
    
    let totalItems = state.cart.reduce((sum, i) => sum + i.qty, 0);
    if (totalItems < 2) return Swal.fire('Error', 'Not enough items to split. You need at least 2 items.', 'warning');

    let html = `<div style="text-align:left; max-height:350px; overflow-y:auto; padding:5px;">`;
    html += `<div style="font-size:0.9rem; color:gray; margin-bottom:15px;">Select items to move to a new separate bill. <b>They will remain at this table.</b></div>`;
    
    state.cart.forEach((item, idx) => {
        html += `
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #ddd; padding:12px 0;">
                <div style="flex:1; line-height:1.2; padding-right:10px;">
                    <div style="font-weight:bold; font-size:1rem;">${item.name}</div>
                    <div style="font-size:0.85rem; color:var(--text-muted);">Current Check: ${item.qty}</div>
                </div>
                <div style="display:flex; align-items:center; gap:8px; background:#f3f4f6; padding:6px; border-radius:30px;">
                    <span style="font-size:1.5rem; width:35px; height:35px; display:inline-flex; align-items:center; justify-content:center; background:white; border:1px solid #ccc; border-radius:50%; cursor:pointer;" onclick="document.getElementById('sqty_${idx}').stepDown()">−</span>
                    <input type="number" id="sqty_${idx}" value="0" min="0" max="${item.qty}" style="width:30px; text-align:center; background:transparent; border:none; font-weight:bold; font-size:1.2rem; color:var(--brand);" readonly>
                    <span style="font-size:1.5rem; width:35px; height:35px; display:inline-flex; align-items:center; justify-content:center; background:white; border:1px solid #ccc; border-radius:50%; cursor:pointer;" onclick="document.getElementById('sqty_${idx}').stepUp()">+</span>
                </div>
            </div>
        `;
    });
    html += `</div>`;

    const { value: proceed } = await Swal.fire({
        title: '✂️ Split to New Check',
        html: html,
        showCancelButton: true,
        confirmButtonText: 'Move to New Check',
        confirmButtonColor: '#6B4226',
        width: 500,
        preConfirm: () => {
            let toMove = [];
            let keepCart = [];
            let totalMoved = 0;
            
            state.cart.forEach((item, idx) => {
                let moveQty = parseInt(document.getElementById(`sqty_${idx}`).value) || 0;
                if (moveQty > 0) {
                    totalMoved += moveQty;
                    let clonedMove = JSON.parse(JSON.stringify(item));
                    clonedMove.qty = moveQty;
                    clonedMove.discount_amount = 0; clonedMove.discount_note = '';
                    toMove.push(clonedMove);
                }
                
                let keepQty = item.qty - moveQty;
                if (keepQty > 0) {
                    let clonedKeep = JSON.parse(JSON.stringify(item));
                    clonedKeep.qty = keepQty;
                    keepCart.push(clonedKeep);
                }
            });
            
            if (totalMoved === 0) { Swal.showValidationMessage('Select at least one item to move.'); return false; }
            if (totalMoved === totalItems) { Swal.showValidationMessage('You cannot move everything. Use the Transfer button instead.'); return false; }
            return { toMove: toMove, keepCart: keepCart };
        }
    });

    if (proceed) {
        Swal.fire({title: 'Splitting...', text: 'Updating databases', allowOutsideClick:false, didOpen:()=>Swal.showLoading()});
        
        let originalTableName = document.getElementById('tableName').innerText.split(' - ')[0];
        
        // 1. Update Check 1
        state.cart = proceed.keepCart;
        await window.saveOrder(true);
        
        // 2. Setup Check 2 (STAYS ON DINE-IN, STAYS ON SAME TABLE)
        state.activeOrderId = 'new';
        state.customer_name = 'Split Check'; // Helps identify it in the modal
        state.cart = proceed.toMove; 
        state.discount_id = null; state.discount_note = ''; state.senior_details = []; state.custom_discount = {is_active:false}; state.amount_paid = 0;
        
        await window.saveOrder(true);
        
        Swal.fire({
            icon: 'success', title: 'Split Successful!', text: `Items moved to a new Check on this table.`,
            confirmButtonText: 'Open New Check', confirmButtonColor: '#2e7d32'
        });
        
        document.getElementById('tableName').innerText = originalTableName + ' - Split Check';
        renderCart();
    }
};