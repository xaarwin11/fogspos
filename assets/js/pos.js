let state = {
    products: [], modifiers: [], discounts: [], categories: [],
    cart: [], activeTableId: null, activeOrderId: null,
    mode: 'dine_in', currentCategory: 'All',
    discount_id: null, discount_amount: 0, discount_note: '', senior_details: [],
    amount_paid: 0 
};

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
    filtered.forEach(p => {
        const el = document.createElement('div'); el.className = 'product-card';
        el.innerHTML = `<div>${p.name}</div><div style="color:var(--brand)">₱${parseFloat(p.price).toFixed(2)}</div>`;
        el.onclick = () => handleProductSelection(p);
        con.appendChild(el);
    });
}

async function handleProductSelection(p) {
    if (state.mode === 'dine_in' && !state.activeTableId) return Swal.fire('Table Required', 'Select a table first', 'warning');
    if (state.mode === 'takeout' && !state.activeOrderId && state.activeOrderId !== 'new') return Swal.fire('Takeout Required', 'Select a takeout order first', 'warning');

    let item = { id: p.id, name: p.name, price: parseFloat(p.price), qty: 1, variation_id: null, variation_name: null, modifiers: [], discount_amount: 0, discount_note: '' };

    if (p.variations && p.variations.length > 0) {
        const { value: vId } = await Swal.fire({
            title: 'Select Size', input: 'radio',
            inputOptions: p.variations.reduce((a, v) => ({...a, [v.id]: `${v.name} (₱${v.price})`}), {}),
            confirmButtonColor: '#6B4226'
        });
        if (!vId) return;
        const v = p.variations.find(v => v.id == vId);
        item.variation_id = v.id; item.variation_name = v.name;
        item.name = `${p.name} (${v.name})`; item.price = parseFloat(v.price);
    }

    const pMods = p.modifiers || [];
    if (pMods.length > 0) {
        const allowed = state.modifiers.filter(m => pMods.includes(Number(m.id)) || pMods.includes(String(m.id)));
        if (allowed.length > 0) {
            const { value: selectedMods } = await Swal.fire({
                title: 'Add-ons?',
                html: `<div class="swal-list">${allowed.map(m => `<label class="swal-check" style="display:flex; justify-content:space-between; padding:10px; border-bottom:1px solid #eee;"><span>${m.name} (+₱${m.price})</span><input type="checkbox" class="mod-cb" value="${m.id}" data-name="${m.name}" data-price="${m.price}"></label>`).join('')}</div>`,
                preConfirm: () => Array.from(document.querySelectorAll('.mod-cb:checked')).map(i => ({ id: parseInt(i.value), name: i.dataset.name, price: parseFloat(i.dataset.price) })),
                confirmButtonColor: '#6B4226'
            });
            if (selectedMods) item.modifiers = selectedMods;
        }
    }

    const modKey = item.modifiers.map(m => m.id).sort().join(',');
    const uniqueKey = `${item.id}-${item.variation_id}-${modKey}`;
    const existingIndex = state.cart.findIndex(i => `${i.id}-${i.variation_id}-${i.modifiers.map(m => m.id).sort().join(',')}` === uniqueKey);

    if (existingIndex > -1) state.cart[existingIndex].qty += 1;
    else state.cart.push(item);
    
    renderCart();
}

function renderCart() {
    const con = document.getElementById('cartContainer');
    if (!con) return;
    con.innerHTML = '';
    let previewSubtotal = 0;

    state.cart.forEach((item, idx) => {
        const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
        const itemDiscount = parseFloat(item.discount_amount) || 0;
        const lineTotal = ((item.price + mCost) * item.qty) - itemDiscount;
        previewSubtotal += lineTotal;

        const discountBadge = itemDiscount > 0 ? `<div style="font-size:0.75rem; color:var(--danger); margin-top:2px;">Discount: -₱${itemDiscount.toFixed(2)} <small>(${item.discount_note})</small></div>` : '';

        con.innerHTML += `
            <div class="bill-item">
                <div style="display:flex; justify-content:space-between; font-weight:800;">
                    <span style="cursor:pointer; border-bottom:1px dashed var(--brand);" onclick="editCartItem(${idx})" title="Tap to edit item">${item.name}</span>
                    <span style="cursor:pointer; border-bottom:1px dashed var(--danger); padding-bottom:2px;" onclick="promptItemDiscount(${idx})" title="Tap to add item discount">₱${lineTotal.toFixed(2)}</span>
                </div>
                ${item.modifiers.length > 0 ? `<div class="bill-mods">${item.modifiers.map(m => '+ ' + m.name).join(', ')}</div>` : ''}
                ${discountBadge}
                <div class="bill-actions" style="display:flex; justify-content:space-between; align-items:center; margin-top:5px;">
                    <div class="qty-controls" style="display:flex; align-items:center; gap:10px;">
                        <button class="qty-btn" onclick="updateQty(${idx}, -1)">-</button>
                        <span>${item.qty}</span>
                        <button class="qty-btn" onclick="updateQty(${idx}, 1)">+</button>
                    </div>
                    <span class="remove-link" onclick="confirmRemoveItem(${idx})" style="color:red; font-size:0.8rem; cursor:pointer;">Remove</span>
                </div>
            </div>`;
    });

    let previewGrandTotal = previewSubtotal - (state.discount_amount || 0);

    document.getElementById('txtSubtotal').innerText = '₱' + previewSubtotal.toFixed(2);
    document.getElementById('txtGrandTotal').innerText = '₱' + Math.max(0, previewGrandTotal).toFixed(2);
    document.getElementById('summaryArea').style.display = previewSubtotal > 0 ? 'block' : 'none';

    let discRow = document.getElementById('appliedDiscountRow');
    if (state.discount_amount > 0 || state.discount_id) {
        if (!discRow) {
            document.getElementById('summaryArea').insertAdjacentHTML('beforeend', `
                <div id="appliedDiscountRow" class="math-row" style="display:flex; justify-content:space-between; font-size:0.9rem; color:var(--danger); margin-top:5px;">
                    <span id="txtDiscName">${state.discount_note || 'Order Discount'}</span>
                    <span id="txtDiscAmount">-₱${(state.discount_amount || 0).toFixed(2)}</span>
                </div>`);
        } else {
            document.getElementById('txtDiscName').innerText = state.discount_note || 'Order Discount';
            document.getElementById('txtDiscAmount').innerText = '-₱' + (state.discount_amount || 0).toFixed(2);
        }
    } else if (discRow) discRow.remove();
}

window.updateQty = function(idx, delta) {
    state.cart[idx].qty += delta;
    if (state.cart[idx].qty <= 0) state.cart.splice(idx, 1);
    renderCart();
};

window.confirmRemoveItem = function(idx) {
    Swal.fire({
        title: 'Remove Item?', text: state.cart[idx].name, icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Yes, remove it'
    }).then((res) => {
        if (res.isConfirmed) { state.cart.splice(idx, 1); renderCart(); }
    });
};

window.editCartItem = async function(idx) {
    const item = state.cart[idx];
    const p = state.products.find(x => x.id == item.id);
    if (!p) return;
    
    let selectedVar = item.variation_id;
    let selectedMods = item.modifiers ? item.modifiers.map(m => m.id) : [];

    if (p.variations && p.variations.length > 0) {
        const { value: vId } = await Swal.fire({
            title: 'Update Size', input: 'radio', inputValue: selectedVar,
            inputOptions: p.variations.reduce((a, v) => ({...a, [v.id]: `${v.name} (₱${v.price})`}), {}),
            confirmButtonColor: '#6B4226', showCancelButton: true
        });
        if (vId) {
            const v = p.variations.find(v => v.id == vId);
            item.variation_id = v.id; item.variation_name = v.name;
            item.name = `${p.name} (${v.name})`; item.price = parseFloat(v.price);
        }
    }

    const pMods = p.modifiers || [];
    if (pMods.length > 0) {
        const allowed = state.modifiers.filter(m => pMods.includes(Number(m.id)) || pMods.includes(String(m.id)));
        if (allowed.length > 0) {
            const { value: newMods } = await Swal.fire({
                title: 'Update Add-ons',
                html: `<div class="swal-list">${allowed.map(m => {
                    const isChecked = selectedMods.includes(m.id) ? 'checked' : '';
                    return `<label class="swal-check" style="display:flex; justify-content:space-between; padding:10px; border-bottom:1px solid #eee;"><span>${m.name} (+₱${m.price})</span><input type="checkbox" class="mod-cb" value="${m.id}" data-name="${m.name}" data-price="${m.price}" ${isChecked}></label>`;
                }).join('')}</div>`,
                preConfirm: () => Array.from(document.querySelectorAll('.mod-cb:checked')).map(i => ({ id: parseInt(i.value), name: i.dataset.name, price: parseFloat(i.dataset.price) })),
                confirmButtonColor: '#6B4226', showCancelButton: true
            });
            if (newMods) item.modifiers = newMods;
        }
    }

    const modKey = item.modifiers.map(m => m.id).sort().join(',');
    const uniqueKey = `${item.id}-${item.variation_id}-${modKey}`;
    const dupIdx = state.cart.findIndex((i, index) => index !== idx && `${i.id}-${i.variation_id}-${i.modifiers.map(m=>m.id).sort().join(',')}` === uniqueKey);
    
    if (dupIdx > -1) { state.cart[dupIdx].qty += item.qty; state.cart.splice(idx, 1); }
    renderCart();
};

window.promptItemDiscount = async function(idx) {
    const item = state.cart[idx];
    const { value: formValues } = await Swal.fire({
        title: 'Custom Item Discount',
        html: `
            <input type="number" id="idisc-amount" class="swal2-input" placeholder="Amount (₱)" step="0.01" value="${item.discount_amount || ''}">
            <input type="text" id="idisc-note" class="swal2-input" placeholder="Reason/Note" value="${item.discount_note || ''}">
            <button class="btn danger" style="width:100%; margin-top:10px;" onclick="document.getElementById('idisc-amount').value=''; document.getElementById('idisc-note').value='';">Clear Discount</button>
        `,
        focusConfirm: false,
        preConfirm: () => { return { amount: parseFloat(document.getElementById('idisc-amount').value) || 0, note: document.getElementById('idisc-note').value || 'Custom' } }
    });

    if (formValues) {
        state.cart[idx].discount_amount = formValues.amount;
        state.cart[idx].discount_note = formValues.note;
        renderCart();
    }
};

window.clearCart = function() {
    Swal.fire({ title: 'Clear & Void Order?', text: 'This will free up the table.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' }).then(async (res) => {
        if (res.isConfirmed) {
            if (state.activeOrderId && state.activeOrderId !== 'new') {
                const response = await fetch('../api/clear_order.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({order_id: state.activeOrderId}) });
                const data = await response.json();
                if (!data.success) return Swal.fire('Error', data.error, 'error');
            }
            state.cart = []; state.discount_id = null; state.discount_amount = 0; state.discount_note = ''; state.senior_details = []; state.amount_paid = 0;
            state.activeTableId = null; state.activeOrderId = null;
            document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
            renderCart();
            if (typeof showTablePopup === 'function' && state.mode !== 'takeout') showTablePopup(); 
        }
    });
};

window.applyDiscountPopup = async function() {
    if(state.cart.length === 0) return Swal.fire('Empty', 'Add items first', 'warning');
    let html = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">';
    state.discounts.forEach(d => { html += `<button class="btn secondary" onclick="selectDiscount(${d.id})">${d.name}</button>`; });
    html += `<button class="btn danger" style="grid-column: span 2;" onclick="selectDiscount(0)">Remove Order Discount</button></div>`;
    Swal.fire({ title: 'Select Discount', html: html, showConfirmButton: false, showCancelButton: true });
};

window.selectDiscount = async function(discId) {
    if(discId === 0) {
        state.discount_id = null; state.discount_note = ''; state.senior_details = []; state.discount_amount = 0;
        Swal.close(); renderCart();
        return Swal.fire({title: 'Removed', text: 'Discount cleared', icon: 'success', timer: 1000});
    }

    const d = state.discounts.find(x => x.id == discId);
    state.discount_id = d.id;

    if (d.name.toLowerCase().includes('senior') || d.target_type === 'highest') {
        const { value: details } = await Swal.fire({
            title: 'Senior/PWD Details',
            html: `
                <div id="snr-box" style="text-align:left; max-height:200px; overflow-y:auto; padding:5px;">
                    <div class="s-row" style="margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid #ddd;">
                        <select class="swal2-input s-type" style="margin:5px 0;"><option value="SC">Senior (SC)</option><option value="PWD">PWD</option></select>
                        <input type="text" class="swal2-input s-id" placeholder="ID Number (Req)" style="margin:5px 0;">
                        <input type="text" class="swal2-input s-name" placeholder="Name (Opt)" style="margin:5px 0;">
                    </div>
                </div>
                <button type="button" class="btn secondary" onclick="addSeniorRow()" style="width:100%">+ Add Another Person</button>
            `,
            didOpen: () => {
                window.addSeniorRow = () => { document.getElementById('snr-box').insertAdjacentHTML('beforeend', `<div class="s-row" style="margin-bottom:10px; padding-bottom:10px; border-bottom:1px solid #ddd;"><select class="swal2-input s-type" style="margin:5px 0;"><option value="SC">Senior (SC)</option><option value="PWD">PWD</option></select><input type="text" class="swal2-input s-id" placeholder="ID Number (Req)" style="margin:5px 0;"><input type="text" class="swal2-input s-name" placeholder="Name (Opt)" style="margin:5px 0;"></div>`); };
            },
            preConfirm: () => {
                let data = [];
                for (let r of document.querySelectorAll('.s-row')) {
                    const id = r.querySelector('.s-id').value;
                    if (!id) { Swal.showValidationMessage('ID Number is required'); return false; }
                    data.push({ type: r.querySelector('.s-type').value, id: id, name: r.querySelector('.s-name').value });
                }
                return data;
            }
        });
        if (details) {
            state.senior_details = details; state.discount_note = `SC/PWD (${details.length} Pax)`;
            Swal.fire({title: 'Applied', text:'Will calculate upon save.', icon:'success', timer: 1000});
            renderCart();
        } else { state.discount_id = null; }
    } else {
        state.discount_note = d.name;
        Swal.fire({title: 'Applied', text:'Will calculate upon save.', icon:'success', timer: 1000});
        renderCart();
    }
};

window.saveOrder = async function(silent = false) {
    if (state.cart.length === 0) return Swal.fire('Empty', 'Add items first', 'warning');
    const response = await fetch('../api/save_order.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            items: state.cart, table_id: state.activeTableId, order_id: state.activeOrderId === 'new' ? null : state.activeOrderId,
            order_type: state.mode, discount_id: state.discount_id, discount_note: state.discount_note, senior_details: state.senior_details
        })
    });
    const result = await response.json();
    if (result.success) {
        state.activeOrderId = result.order_id;
        document.getElementById('txtGrandTotal').innerText = '₱' + result.total; 
        
        if(state.activeOrderId) {
            const r = await fetch(`../api/get_active_order.php?order_id=${state.activeOrderId}`);
            const d = await r.json();
            if(d.success) {
                state.cart = d.items;
                state.discount_amount = parseFloat(d.order_info.discount_total);
                state.discount_note = d.order_info.discount_note;
                state.amount_paid = parseFloat(d.order_info.amount_paid) || 0; 
                renderCart();
            }
        }
        if(!silent) Swal.fire({ icon: 'success', title: 'Order Saved', timer: 1000, showConfirmButton: false });
    } else Swal.fire('Error', result.error, 'error');
};

window.splitBillByItem = async function(grandTotal) {
    if (state.cart.length === 0) return;
    
    let checklistHtml = `<div style="text-align:left; max-height:250px; overflow-y:auto; border:1px solid #eee; padding:10px;">`;
    state.cart.forEach((item, idx) => {
        const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
        const lineTotal = ((item.price + mCost) * item.qty) - (item.discount_amount || 0);
        checklistHtml += `
            <label style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px dashed #eee; cursor:pointer;">
                <span><input type="checkbox" class="split-cb" value="${lineTotal}" data-name="${item.name}"> ${item.qty}x ${item.name}</span>
                <span style="font-weight:bold;">₱${lineTotal.toFixed(2)}</span>
            </label>
        `;
    });
    checklistHtml += `</div>`;

    const { value: splitTotal } = await Swal.fire({
        title: 'Select Items to Pay',
        html: checklistHtml,
        showCancelButton: true,
        confirmButtonText: 'Use Selected Total',
        preConfirm: () => {
            let sum = 0;
            document.querySelectorAll('.split-cb:checked').forEach(cb => sum += parseFloat(cb.value));
            if (sum <= 0) { Swal.showValidationMessage('Select at least one item'); return false; }
            return sum;
        }
    });

    if (splitTotal) {
        document.getElementById('pay-amount').value = splitTotal.toFixed(2);
        // Trigger the input event to update change calculations
        document.getElementById('pay-amount').dispatchEvent(new Event('input', { bubbles: true }));
    }
};

window.checkout = async function() {
    if (state.cart.length === 0) return Swal.fire('Empty', 'Nothing to charge!', 'warning');
    
    await window.saveOrder(true);
    if (!state.activeOrderId) return; 

    const grandTotal = parseFloat(document.getElementById('txtGrandTotal').innerText.replace('₱', ''));
    const balance = grandTotal - state.amount_paid;
    
    if (balance <= 0) return Swal.fire('Paid', 'This order is already fully paid.', 'info');

    const denominations = [20, 50, 100, 200, 500, 1000];

    const { value: formValues } = await Swal.fire({
        title: 'Checkout',
        html: `
            <div class="checkout-summary" style="background:#f9fafb; padding:15px; border-radius:10px; margin-bottom:15px; border:1px solid #eee; text-align:left;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;"><span>Bill Total:</span> <span>₱${grandTotal.toFixed(2)}</span></div>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; color:var(--text-muted);"><span>Already Paid:</span> <span>₱${state.amount_paid.toFixed(2)}</span></div>
                <hr style="border:0; border-top:1px dashed #ccc; margin-bottom:10px;">
                <div style="display:flex; justify-content:space-between;"><span>Balance Due:</span> <span style="font-weight:bold; color:var(--danger); font-size:1.5rem;">₱${balance.toFixed(2)}</span></div>
                <div style="display:flex; justify-content:space-between; color:var(--success); margin-top:5px;"><span>Change:</span> <span id="co-change" style="font-weight:bold; font-size:1.2rem;">₱0.00</span></div>
            </div>
            
            <button class="btn" style="width:100%; margin-bottom:15px; background:var(--tan);" onclick="splitBillByItem(${balance})">✂️ Split by Specific Items</button>
            
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; margin-bottom:15px;">
                <button class="btn secondary" onclick="addTendered(${balance})">Exact</button>
                ${denominations.map(amt => `<button class="btn secondary" onclick="addTendered(${amt})">+₱${amt}</button>`).join('')}
                <button class="btn danger" onclick="document.getElementById('pay-amount').value=''; document.getElementById('co-change').innerText='₱0.00';">Clear</button>
            </div>

            <input type="number" id="pay-amount" class="search-bar" placeholder="Amount Tendered" step="0.01" style="font-size:2rem; text-align:center; height:70px; margin-bottom:10px;">
            
            <select id="pay-method" class="search-bar" style="text-align:center; font-weight:bold;">
                <option value="cash">💵 Cash</option>
                <option value="gcash">📱 GCash</option>
                <option value="card">💳 Card</option>
            </select>
        `,
        showCancelButton: true, confirmButtonText: 'PROCESS PAYMENT', confirmButtonColor: '#2e7d32',
        didOpen: () => {
            const amtInput = document.getElementById('pay-amount');
            amtInput.focus();
            amtInput.addEventListener('input', () => {
                const tendered = parseFloat(amtInput.value) || 0;
                const change = tendered - balance;
                if (change >= 0) {
                    document.getElementById('co-change').innerText = '₱' + change.toFixed(2);
                    document.getElementById('co-change').style.color = 'var(--success)';
                } else {
                    document.getElementById('co-change').innerText = 'Partial (Rem: ₱' + Math.abs(change).toFixed(2) + ')';
                    document.getElementById('co-change').style.color = 'var(--brand-light)';
                }
            });
        },
        preConfirm: () => {
            const method = document.getElementById('pay-method').value;
            const amount = parseFloat(document.getElementById('pay-amount').value);
            if(isNaN(amount) || amount <= 0) { Swal.showValidationMessage('Enter a valid amount'); return false; }
            return { method, amount };
        }
    });

    if(formValues) {
        const res = await fetch('../api/checkout.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ order_id: state.activeOrderId, method: formValues.method, amount: formValues.amount })
        });
        const data = await res.json();
        if(data.success) {
            if (data.is_fully_paid) {
                Swal.fire({title: 'Fully Paid!', text: 'Change: ₱' + data.change.toFixed(2), icon: 'success', confirmButtonColor: '#6B4226'});
                state.cart = []; state.discount_id = null; state.discount_amount = 0; state.discount_note = ''; state.senior_details = []; state.amount_paid = 0;
                state.activeTableId = null; state.activeOrderId = null;
                document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
                renderCart();
            } else {
                Swal.fire({title: 'Partial Payment Saved', text: 'Remaining Balance: ₱' + Math.abs(formValues.amount - balance).toFixed(2), icon: 'info', confirmButtonColor: '#6B4226'});
                state.amount_paid += formValues.amount; 
            }
        } else { Swal.fire('Error', data.error, 'error'); }
    }
};

window.addTendered = function(amount) {
    const input = document.getElementById('pay-amount');
    const current = parseFloat(input.value) || 0;
    input.value = (current + amount).toFixed(2);
    input.dispatchEvent(new Event('input', { bubbles: true }));
};