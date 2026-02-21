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
    if (state.mode === 'takeout' && !state.activeOrderId && state.activeOrderId !== 'new') return Swal.fire('Takeout Required', 'Create a new takeout order first', 'warning');

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
    
    let rawSubtotal = 0;
    let localItemDiscountSum = 0;

    if (state.cart.length === 0) {
        con.innerHTML = '<div style="text-align:center; padding:40px; color:#ccc; font-size:1.1rem;">🛒 Cart is empty</div>';
    } else {
        state.cart.forEach((item, idx) => {
            const mCost = item.modifiers.reduce((s, m) => s + m.price, 0);
            const rawLineTotal = (item.price + mCost) * item.qty;
            rawSubtotal += rawLineTotal;
            
            localItemDiscountSum += parseFloat(item.discount_amount) || 0;
            const discountBadge = item.discount_amount > 0 ? `<div style="font-size:0.75rem; color:var(--danger); margin-top:2px;">Includes ${item.discount_note || 'Discount'}</div>` : '';

            con.innerHTML += `
                <div class="bill-item" style="padding: 10px 0; border-bottom: 1px dashed var(--border); display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                    <div style="flex:1; line-height: 1.2;">
                        <div style="font-weight:700; font-size:0.95rem; cursor:pointer; color:var(--text-main);" onclick="editCartItem(${idx})" title="Tap to edit">${item.name}</div>
                        ${item.modifiers.length > 0 ? `<div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">+ ${item.modifiers.map(m => m.name).join(', ')}</div>` : ''}
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
            `;
        });
    }

    const totalCombinedDiscount = localItemDiscountSum + (parseFloat(state.discount_amount) || 0);
    state.grand_total = Math.max(0, rawSubtotal - totalCombinedDiscount);

    document.getElementById('txtSubtotal').innerText = '₱' + rawSubtotal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('txtGrandTotal').innerText = '₱' + state.grand_total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('summaryArea').style.display = rawSubtotal > 0 ? 'block' : 'none';

    let discRow = document.getElementById('appliedDiscountRow');
    if (totalCombinedDiscount > 0) {
        let displayNote = state.discount_note || 'Total Discount';
        if (state.custom_discount.is_active && state.custom_discount.note) displayNote = "Custom: " + state.custom_discount.note;

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
}

window.updateQty = function(idx, delta) {
    state.cart[idx].qty += delta;
    if (state.cart[idx].qty <= 0) state.cart.splice(idx, 1);
    renderCart();
};

window.confirmRemoveItem = function(idx) {
    Swal.fire({
        title: 'Remove Item?', text: state.cart[idx].name, icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Yes'
    }).then((res) => { if (res.isConfirmed) { state.cart.splice(idx, 1); renderCart(); } });
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
        title: 'Item Discount',
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
    if (state.cart.length === 0 && !state.activeOrderId) return Swal.fire('Empty', 'Your cart is already empty.', 'info');

    Swal.fire({ title: 'Clear & Void Order?', text: 'This will free up the table.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' }).then(async (res) => {
        if (res.isConfirmed) {
            if (state.activeOrderId && state.activeOrderId !== 'new') {
                try { 
                    await fetch('../api/clear_order.php', { 
                        method: 'POST', 
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': getCsrfToken() // CSRF APPLIED
                        }, 
                        body: JSON.stringify({order_id: state.activeOrderId}) 
                    }); 
                } catch (e) {}
            }
            state.cart = []; state.discount_id = null; state.discount_amount = 0; state.discount_note = ''; state.senior_details = []; state.amount_paid = 0; 
            state.customer_name = null;
            state.custom_discount = { is_active: false, type: 'percent', val: 0, target: 'all', note: '' };
            state.activeTableId = null; state.activeOrderId = null; state.grand_total = 0;
            document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
            renderCart();
            if (typeof showTablePopup === 'function' && state.mode !== 'takeout') showTablePopup(); 
        }
    });
};

window.applyDiscountPopup = async function() {
    if(state.cart.length === 0) return Swal.fire('Empty', 'Add items first', 'warning');
    let html = '<div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">';
    state.discounts.forEach(d => { html += `<button class="btn secondary" onclick="selectDiscount(${d.id})">${d.name}</button>`; });
    html += `</div>`;
    html += `<button class="btn" style="width:100%; background:var(--blue); margin-bottom:10px;" onclick="customOrderDiscount()">🌟 Custom Target Discount</button>`;
    html += `<button class="btn danger" style="width:100%;" onclick="selectDiscount(0)">Remove All Discounts</button>`;
    Swal.fire({ title: 'Select Discount', html: html, showConfirmButton: false, showCancelButton: true });
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
            <select id="cd-target" class="search-bar" style="margin-bottom:10px; font-weight:bold;" onchange="document.getElementById('cat-list').style.display = this.value === 'specific' ? 'block' : 'none'">
                <option value="all">Whole Bill (Excl. Alcohol)</option>
                <option value="food">All Food Only</option>
                <option value="drink">All Drinks (Excl. Alcohol)</option>
                <option value="specific">Specific Categories...</option>
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
            if (target === 'specific') {
                document.querySelectorAll('.cd-cat-cb:checked').forEach(cb => targetCats.push(parseInt(cb.value)));
                if (targetCats.length === 0) { Swal.showValidationMessage('Select at least one category!'); return false; }
            }
            return { is_active: true, type: document.getElementById('cd-type').value, val: val, target: target, target_cats: targetCats, note: document.getElementById('cd-note').value || 'Custom Discount' };
        }
    });
    
    if (formValues) {
        state.discount_id = null; state.senior_details = []; state.custom_discount = formValues;
        Swal.fire({title:'Applied', text: 'Will calculate accurately upon save.', icon:'success', timer: 1000});
        renderCart();
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
        Swal.close(); renderCart();
        return Swal.fire({title: 'Removed', text: 'Discount cleared', icon: 'success', timer: 1000});
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
                <input type="text" class="search-bar s-id" placeholder="ID Number" style="margin-bottom:0; padding:10px;">
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
                            <input type="text" class="search-bar s-id" placeholder="ID Number" style="margin-bottom:0; padding:10px;">
                        </div>
                    `); 
                };
            },
            preConfirm: () => {
                let data = [];
                for (let r of document.querySelectorAll('.s-row')) {
                    const id = r.querySelector('.s-id').value;
                    const name = r.querySelector('.s-name').value;
                    const type = r.querySelector('.s-type:checked').value;
                    if (!id || !name) { Swal.showValidationMessage('Name and ID are both required!'); return false; }
                    data.push({ type: type, id: id, name: name });
                }
                return data;
            }
        });
        if (details) {
            state.senior_details = details; state.discount_note = `SC/PWD (${details.length} Pax)`;
            renderCart();
        } else { state.discount_id = null; }
    } else {
        state.discount_note = d.name;
        renderCart();
    }
};

function syncOrderState(d) {
    state.cart = d.items;
    const totalItemDisc = d.items.reduce((sum, item) => sum + (parseFloat(item.discount_amount) || 0), 0);
    let globalDisc = (parseFloat(d.order_info.discount_total) || 0) - totalItemDisc;
    if (globalDisc < 0.01) globalDisc = 0; 
    
    state.discount_amount = globalDisc;
    
    if (d.order_info.discount_note && d.order_info.discount_note.startsWith('Custom:')) {
        state.custom_discount = { is_active: true, note: d.order_info.discount_note.replace('Custom: ', '') };
    }
    
    state.discount_note = d.order_info.discount_note || '';
    state.discount_id = d.order_info.discount_id || null;
    state.amount_paid = parseFloat(d.order_info.amount_paid) || 0;
    state.customer_name = d.order_info.customer_name || null;
}

window.saveOrder = async function(silent = false) {
    if (state.cart.length === 0) return Swal.fire('Empty', 'Add items first', 'warning');
    const response = await fetch('../api/save_order.php', {
        method: 'POST', 
        headers: { 
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken() // CSRF APPLIED
        },
        body: JSON.stringify({
            items: state.cart, table_id: state.activeTableId, order_id: state.activeOrderId === 'new' ? null : state.activeOrderId,
            order_type: state.mode, discount_id: state.discount_id, discount_note: state.discount_note, senior_details: state.senior_details,
            custom_discount: state.custom_discount, customer_name: state.customer_name
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
    } else Swal.fire('Error', result.error, 'error');
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
        if(d.success) { syncOrderState(d); renderCart(); }
    } else { 
        state.cart = []; state.discount_amount = 0; state.discount_note = ''; state.discount_id = null; state.amount_paid = 0;
        state.custom_discount = { is_active: false };
        renderCart();
    }
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
    if(method === 'cash') {
        document.getElementById('btn-cash').style = "flex:1; padding:15px; font-size:1.1rem; border-radius:8px; border:2px solid var(--brand); background:var(--brand); color:white; font-weight:bold; cursor:pointer;";
        document.getElementById('btn-gcash').style = "flex:1; padding:15px; font-size:1.1rem; border-radius:8px; border:2px solid #ccc; background:white; color:gray; font-weight:bold; cursor:pointer;";
    } else {
        document.getElementById('btn-gcash').style = "flex:1; padding:15px; font-size:1.1rem; border-radius:8px; border:2px solid #005ce6; background:#005ce6; color:white; font-weight:bold; cursor:pointer;";
        document.getElementById('btn-cash').style = "flex:1; padding:15px; font-size:1.1rem; border-radius:8px; border:2px solid #ccc; background:white; color:gray; font-weight:bold; cursor:pointer;";
    }
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
        state.cart = []; state.discount_id = null; state.discount_amount = 0; state.discount_note = ''; state.senior_details = []; state.amount_paid = 0; state.customer_name = null;
        state.activeTableId = null; state.activeOrderId = null; state.grand_total = 0;
        document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
        renderCart(); return;
    }

    const denominations = [20, 50, 100, 200, 500, 1000];
    const initialInput = prefillAmount ? prefillAmount.toFixed(2) : '';
    
    let splitDetailsHtml = '';
    if (selectedItems && selectedItems.length > 0) {
        splitDetailsHtml = `<div style="font-size:0.85rem; color:var(--brand-dark); background:#fff3e0; padding:10px; border-radius:6px; margin-bottom:10px; border:1px solid #ffcc80; text-align:left;"><b>Paying specifically for:</b><br>${selectedItems.join(', ')}</div>`;
    }

    const { value: formValues } = await Swal.fire({
        title: 'Checkout',
        html: `
            <div class="checkout-summary" style="background:#f9fafb; padding:15px; border-radius:10px; margin-bottom:15px; border:1px solid #eee; text-align:left;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;"><span>Bill Total:</span> <span>₱${state.grand_total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></div>
                <div style="display:flex; justify-content:space-between; margin-bottom:10px; color:var(--text-muted);"><span>Already Paid:</span> <span>₱${state.amount_paid.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></div>
                <hr style="border:0; border-top:1px dashed #ccc; margin-bottom:10px;">
                <div style="display:flex; justify-content:space-between;"><span>Balance Due:</span> <span style="font-weight:bold; color:var(--danger); font-size:1.5rem;">₱${balance.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span></div>
                <div style="display:flex; justify-content:space-between; color:var(--success); margin-top:5px;"><span>Change:</span> <span id="co-change" style="font-weight:bold; font-size:1.2rem;">₱0.00</span></div>
            </div>
            ${splitDetailsHtml}
            <button class="btn" style="width:100%; margin-bottom:15px; background:var(--tan);" onclick="splitBillByItem(${balance})">✂️ Split by Specific Items</button>
            <div style="display:flex; gap:10px; margin-bottom:15px;">
                <button type="button" id="btn-cash" style="flex:1; padding:15px; font-size:1.1rem; border-radius:8px; border:2px solid var(--brand); background:var(--brand); color:white; font-weight:bold; cursor:pointer;" onclick="setPayMethod('cash')">💵 CASH</button>
                <button type="button" id="btn-gcash" style="flex:1; padding:15px; font-size:1.1rem; border-radius:8px; border:2px solid #ccc; background:white; color:gray; font-weight:bold; cursor:pointer;" onclick="setPayMethod('gcash')">📱 GCASH</button>
            </div>
            <input type="hidden" id="pay-method" value="cash">
            <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; margin-bottom:15px;">
                <button class="btn secondary" onclick="addTendered(${prefillAmount || balance})">Exact</button>
                ${denominations.map(amt => `<button class="btn secondary" onclick="addTendered(${amt})">+₱${amt}</button>`).join('')}
                <button class="btn danger" onclick="document.getElementById('pay-amount').value=''; document.getElementById('pay-amount').dispatchEvent(new Event('input', {bubbles:true}));">Clear</button>
            </div>
            <input type="number" id="pay-amount" class="search-bar" value="${initialInput}" placeholder="Amount Tendered" step="0.01" style="font-size:2rem; text-align:center; height:70px; margin-bottom:10px;">
        `,
        showCancelButton: true, confirmButtonText: 'PROCESS PAYMENT', confirmButtonColor: '#2e7d32',
        didOpen: () => {
            const amtInput = document.getElementById('pay-amount'); amtInput.focus();
            const updateChange = () => {
                const targetDue = prefillAmount || balance; const tendered = parseFloat(amtInput.value) || 0;
                const change = Math.round((tendered - targetDue) * 100) / 100;
                if (change >= 0) { document.getElementById('co-change').innerText = '₱' + change.toFixed(2); document.getElementById('co-change').style.color = 'var(--success)'; } 
                else { document.getElementById('co-change').innerText = 'Partial (Rem: ₱' + Math.abs(change).toFixed(2) + ')'; document.getElementById('co-change').style.color = 'var(--brand-light)'; }
            };
            amtInput.addEventListener('input', updateChange); if (prefillAmount) updateChange();
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
            method: 'POST', 
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken() // CSRF APPLIED
            },
            body: JSON.stringify({ order_id: state.activeOrderId, method: formValues.method, amount: formValues.amount, customer_name: state.customer_name })
        });
        const data = await res.json();
        if(data.success) {
            if (data.is_fully_paid) {
                const paidOrderId = state.activeOrderId;
                Swal.fire({
                    title: 'Fully Paid!', text: 'Change: ₱' + data.change.toFixed(2), icon: 'success', 
                    showCancelButton: true, confirmButtonText: '🖨️ Print Receipt', cancelButtonText: 'Done', confirmButtonColor: '#2e7d32'
                }).then((res) => { if (res.isConfirmed) fetch(`../api/print_order.php?order_id=${paidOrderId}&type=receipt`); });

                state.cart = []; state.discount_id = null; state.discount_amount = 0; state.discount_note = ''; state.senior_details = []; state.amount_paid = 0; state.customer_name = null;
                state.custom_discount = { is_active: false, type: 'percent', val: 0, target: 'all', note: '' };
                state.activeTableId = null; state.activeOrderId = null; state.grand_total = 0;
                document.getElementById('tableName').innerText = state.mode === 'takeout' ? 'Select Takeout' : 'Select Table';
                renderCart();
            } else {
                Swal.fire({title: 'Partial Payment Saved', text: 'Remaining Balance: ₱' + Math.abs(formValues.amount - balance).toFixed(2), icon: 'info', confirmButtonColor: '#6B4226'});
                state.amount_paid += formValues.amount; 
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