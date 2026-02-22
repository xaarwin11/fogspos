<?php
require_once '../db.php';
session_start();

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    header("Location: ../pos/index.php"); 
    exit; 
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .settings-layout { display: flex; gap: 20px; max-width: 1200px; margin: 30px auto; padding: 0 20px; min-height: calc(100vh - 120px); }
        .settings-sidebar { width: 250px; background: white; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); padding: 15px 0; height: fit-content; }
        .tab-btn { display: block; width: 100%; text-align: left; padding: 15px 20px; background: transparent; border: none; border-left: 4px solid transparent; font-size: 1rem; font-weight: 600; color: var(--text-muted); cursor: pointer; transition: 0.2s; }
        .tab-btn:hover { background: #fdfaf6; color: var(--brand); }
        .tab-btn.active { background: #fdfaf6; color: var(--brand); border-left-color: var(--brand); }
        .settings-content { flex: 1; background: white; border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow); padding: 30px; }
        .tab-pane { display: none; animation: fadeIn 0.3s ease-in-out; }
        .tab-pane.active { display: block; }
        .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; }
        .header-row h2 { margin: 0; color: var(--brand-dark); }
        .settings-table { width: 100%; border-collapse: collapse; }
        .settings-table th, .settings-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        .settings-table th { background: #f9fafb; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; }
        .action-links span { cursor: pointer; font-weight: bold; margin-right: 15px; }
        .action-links span.edit { color: var(--blue); }
        .action-links span.delete { color: var(--danger); }
        .sys-form-group { margin-bottom: 15px; }
        .sys-form-group label { display: block; font-weight: bold; margin-bottom: 5px; color: var(--text-main); }
        .sys-form-group input, .sys-form-group select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 768px) {
            .settings-layout { flex-direction: column; }
            .settings-sidebar { width: 100%; display: flex; flex-wrap: wrap; padding: 5px; }
            .tab-btn { width: auto; flex: 1; text-align: center; border-left: none; border-bottom: 4px solid transparent; }
            .tab-btn.active { border-bottom-color: var(--brand); }
            .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        }
        /* --- A4 PAYROLL PRINT STYLES --- */
        @media print {
            body * { visibility: hidden; }
            .settings-layout { margin: 0; padding: 0; }
            #print-payroll-area, #print-payroll-area * { visibility: visible; }
            #print-payroll-area { position: absolute; left: 0; top: 0; width: 100%; width: 210mm; padding: 20mm; background: white; }
            .no-print { display: none !important; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            .print-table th, .print-table td { border: 1px solid #000; padding: 10px; text-align: left; font-size: 14px; }
            .print-table th { background: #eee !important; -webkit-print-color-adjust: exact; }
            .sign-line { border-bottom: 1px solid #000; width: 120px; display: inline-block; }
        }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="settings-layout">
        <aside class="settings-sidebar">
            <button class="tab-btn active" onclick="switchTab('business')">🏢 Business Profile</button>
            <button class="tab-btn" onclick="switchTab('categories')">📁 Categories</button>
            <button class="tab-btn" onclick="switchTab('tables')">🍽️ Tables</button>
            <button class="tab-btn" onclick="switchTab('modifiers')">➕ Modifiers</button>
            <button class="tab-btn" onclick="switchTab('discounts')">🏷️ Discounts</button>
            <button class="tab-btn" onclick="switchTab('printers')">🖨️ Printers & Routing</button>
            <button class="tab-btn" onclick="switchTab('staff')">👥 Staff Members</button>
            <button class="tab-btn" onclick="switchTab('timesheets')">⏰ Timesheets</button>
            <button class="tab-btn" onclick="switchTab('audit')">📋 Audit Logs</button>
        </aside>

        <main class="settings-content">
            
            <div id="tab-business" class="tab-pane active">
                <div class="header-row"><h2>🏢 Business Profile (Prints on Receipt)</h2></div>
                <div class="sys-form-group"><label>Store Name</label><input type="text" id="s_store_name"></div>
                <div class="sys-form-group"><label>Store Address</label><input type="text" id="s_store_address"></div>
                <div class="sys-form-group"><label>Contact / Phone</label><input type="text" id="s_store_phone"></div>
                <button class="btn success" style="width:100%; margin-top:10px;" onclick="saveSystemBatch(['store_name', 'store_address', 'store_phone'])">Save Business Info</button>

                <div class="header-row" style="margin-top: 40px;"><h2>💾 System Backup</h2></div>
                <p style="color:gray; font-size:0.95rem; margin-top:-10px; margin-bottom:15px;">Download a full, offline copy of your entire database (Menus, Sales, Settings) to your computer.</p>
                <a href="../api/backup_db.php" class="btn" style="background:#005ce6; color:white; text-decoration:none; display:inline-block; text-align:center; width:100%;">📥 Download Full Backup (.sql)</a>
            </div>

            <div id="tab-categories" class="tab-pane">
                <div class="header-row"><h2>Menu Categories</h2><button class="btn" onclick="promptCategory()">+ Add Category</button></div>
                <div class="table-responsive">
                    <table class="settings-table"><thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Actions</th></tr></thead><tbody id="cat-tbody"></tbody></table>
                </div>
            </div>

            <div id="tab-tables" class="tab-pane">
                <div class="header-row"><h2>Restaurant Tables</h2><button class="btn" onclick="promptTable()">+ Add Table</button></div>
                <div class="table-responsive">
                    <table class="settings-table"><thead><tr><th>ID</th><th>Number</th><th>Status</th><th>Actions</th></tr></thead><tbody id="tab-tbody"></tbody></table>
                </div>
            </div>

            <div id="tab-modifiers" class="tab-pane">
                <div class="header-row"><h2>Modifiers & Add-ons</h2><button class="btn" onclick="promptModifier()">+ Add Modifier</button></div>
                <div class="table-responsive">
                    <table class="settings-table"><thead><tr><th>ID</th><th>Name</th><th>Price</th><th>Actions</th></tr></thead><tbody id="mod-tbody"></tbody></table>
                </div>
            </div>

            <div id="tab-discounts" class="tab-pane">
                <div class="header-row"><h2>Discount Rules</h2><button class="btn" onclick="promptDiscount()">+ Add Discount</button></div>
                <div class="table-responsive">
                    <table class="settings-table"><thead><tr><th>Name</th><th>Type</th><th>Value</th><th>Target</th><th>Actions</th></tr></thead><tbody id="disc-tbody"></tbody></table>
                </div>
            </div>

            <div id="tab-printers" class="tab-pane">
                <div class="header-row"><h2>Network Printers</h2><button class="btn" onclick="promptPrinter()">+ Add Printer</button></div>
                <div class="table-responsive" style="margin-bottom:30px;">
                    <table class="settings-table"><thead><tr><th>Name</th><th>Type</th><th>IP / Path</th><th>Actions</th></tr></thead><tbody id="print-tbody"></tbody></table>
                </div>
                
                <div class="header-row"><h2>🔀 Printer Routing</h2></div>
                <div class="sys-form-group"><label>Official Receipt Printer (Checkout)</label><select id="s_route_receipt"></select></div>
                <div class="sys-form-group"><label>Kitchen Printer (Food Items)</label><select id="s_route_kitchen"></select></div>
                <div class="sys-form-group"><label>Bar Printer (Drink Items)</label><select id="s_route_bar"></select></div>
                <button class="btn success" style="width:100%; margin-top:10px;" onclick="saveSystemBatch(['route_receipt', 'route_kitchen', 'route_bar'])">Save Routing</button>
            </div>

            <div id="tab-staff" class="tab-pane">
                <div class="header-row"><h2>Staff Management</h2><button class="btn" onclick="promptStaff()">+ Add Staff</button></div>
                <div class="table-responsive">
                    <table class="settings-table"><thead><tr><th>Username</th><th>Role</th><th>Actions</th></tr></thead><tbody id="staff-tbody"></tbody></table>
                </div>
            </div>

            <div id="tab-timesheets" class="tab-pane">
                
                <div class="no-print" style="margin-bottom: 30px; padding-bottom: 30px; border-bottom: 2px dashed #eee;">
                    <div class="header-row" style="margin-bottom:15px;">
                        <h2>💸 Payroll Generator</h2>
                    </div>
                    <div style="background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #eee; display:flex; gap:15px; align-items:flex-end;">
                        <div style="flex:1;">
                            <label style="font-size:0.85rem; font-weight:bold;">Start Date</label>
                            <input type="date" id="pr-start" class="swal2-input" style="margin:0; width:100%; height:40px; font-size:1rem;">
                        </div>
                        <div style="flex:1;">
                            <label style="font-size:0.85rem; font-weight:bold;">End Date</label>
                            <input type="date" id="pr-end" class="swal2-input" style="margin:0; width:100%; height:40px; font-size:1rem;">
                        </div>
                        <button class="btn success" style="height:40px; padding:0 20px;" onclick="generatePayroll()">Calculate</button>
                        <button class="btn secondary" style="height:40px; padding:0 20px;" onclick="window.print()">🖨️ Print A4 Sheet</button>
                    </div>
                </div>

                <div class="no-print">
                    <div class="header-row" style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center;">
                        <h2>⏰ Recent Shifts</h2>
                        <div style="font-size:0.9rem; color:gray;">Showing last 200 shifts</div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="settings-table">
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Hours</th>
                                    <th>Est. Pay</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="ts-tbody"></tbody> </table>
                    </div>
                </div>

                <div id="print-payroll-area" style="display:none;">
                    <div style="text-align:center; margin-bottom:20px;">
                        <h2 style="margin:0;">FogsTasa Cafe - Payroll Report</h2>
                        <p style="margin:5px 0; color:gray;" id="pr-date-label"></p>
                    </div>
                    
                    <table class="print-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Staff Name</th>
                                <th style="text-align:center;">Shifts</th>
                                <th style="text-align:center;">Reg Hrs (Max 9)</th>
                                <th style="text-align:center; color:#d32f2f;">OT Hrs</th>
                                <th style="text-align:right;">Gross Pay</th>
                                <th>Adv / Ded</th>
                                <th style="text-align:right;">Net Pay</th>
                                <th>Signature</th>
                            </tr>
                        </thead>
                        <tbody id="pr-tbody">
                            </tbody>
                    </table>
                    
                    <div style="margin-top: 50px; display: flex; justify-content: space-between;">
                        <div>
                            <p>Prepared by:</p><br>
                            <span class="sign-line" style="width: 200px;"></span><br>
                            <small>Admin / Manager</small>
                        </div>
                        <div>
                            <p>Approved by:</p><br>
                            <span class="sign-line" style="width: 200px;"></span><br>
                            <small>Owner</small>
                        </div>
                    </div>
                </div>

            </div>

            <div id="tab-audit" class="tab-pane">
                <div class="header-row"><h2>Security & Audit Log</h2></div>
                <p style="color:gray; font-size:0.9rem; margin-top:-10px;">A permanent record of sensitive actions (Refunds, Deletions, Payments).</p>
                <div class="table-responsive">
                    <table class="settings-table">
                        <thead><tr><th>Date & Time</th><th>User</th><th>Action Type</th><th>Target</th><th>Detailed Logs</th></tr></thead>
                        <tbody id="audit-tbody"></tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <script>
        let sd = {}; // Global Settings Data
        const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        document.addEventListener('DOMContentLoaded', loadData);

        function switchTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            event.currentTarget.classList.add('active');
            document.getElementById('tab-' + tabId).classList.add('active');
        }

        async function loadData() {
            try {
                const res = await fetch('../api/settings_action.php?action=get_all');
                
                if (!res.ok || res.headers.get('content-type').indexOf('application/json') === -1) {
                    const errorText = await res.text();
                    Swal.fire('System Error', 'The server crashed. Error details:<br>' + errorText.substring(0, 200), 'error');
                    return;
                }

                const data = await res.json();
                if (data.success) {
                    sd = data;
                    renderAll();
                } else { Swal.fire('Database Error', data.error, 'error'); }
            } catch (e) { 
                Swal.fire('Connection Error', e.message, 'error');
            }
        }

        function renderAll() {
            const getSet = (key) => { const s = sd.settings.find(x => x.setting_key === key); return s ? s.setting_value : ''; };
            document.getElementById('s_store_name').value = getSet('store_name');
            document.getElementById('s_store_address').value = getSet('store_address');
            document.getElementById('s_store_phone').value = getSet('store_phone');

            let pOptions = `<option value="0">None / Disabled</option>`;
            sd.printers.forEach(p => pOptions += `<option value="${p.id}">${p.name} (${p.path})</option>`);
            ['route_receipt', 'route_kitchen', 'route_bar'].forEach(k => {
                const el = document.getElementById('s_' + k);
                el.innerHTML = pOptions;
                el.value = getSet(k) || '0';
            });

            let html = '';
            sd.categories.forEach(c => html += `<tr><td>${c.id}</td><td style="font-weight:bold;">${c.name}</td><td>${c.cat_type.toUpperCase()}</td><td class="action-links"><span class="edit" onclick='promptCategory(${JSON.stringify(c)})'>Edit</span><span class="delete" onclick="del('category', ${c.id})">Delete</span></td></tr>`);
            document.getElementById('cat-tbody').innerHTML = html;

            html = '';
            sd.tables.forEach(t => html += `<tr><td>${t.id}</td><td style="font-weight:bold;">${t.table_number}</td><td>${t.status}</td><td class="action-links"><span class="edit" onclick='promptTable(${JSON.stringify(t)})'>Edit</span><span class="delete" onclick="del('table', ${t.id})">Delete</span></td></tr>`);
            document.getElementById('tab-tbody').innerHTML = html;

            html = '';
            sd.modifiers.forEach(m => html += `<tr><td>${m.id}</td><td style="font-weight:bold;">${m.name}</td><td>₱${m.price}</td><td class="action-links"><span class="edit" onclick='promptModifier(${JSON.stringify(m)})'>Edit</span><span class="delete" onclick="del('modifier', ${m.id})">Delete</span></td></tr>`);
            document.getElementById('mod-tbody').innerHTML = html;

            html = '';
            sd.discounts.forEach(d => html += `<tr><td style="font-weight:bold;">${d.name}</td><td>${d.type}</td><td>${d.type==='percent'? d.value+'%' : '₱'+d.value}</td><td>${d.target_type}</td><td class="action-links"><span class="edit" onclick='promptDiscount(${JSON.stringify(d)})'>Edit</span><span class="delete" onclick="del('discount', ${d.id})">Delete</span></td></tr>`);
            document.getElementById('disc-tbody').innerHTML = html;

            html = '';
            sd.printers.forEach(p => {
                const sizeLabel = p.character_limit == 48 ? '80mm' : '58mm';
                html += `<tr><td style="font-weight:bold;">${p.name}</td><td>${p.connection_type} (${sizeLabel})</td><td>${p.path}</td><td class="action-links"><span class="edit" onclick='promptPrinter(${JSON.stringify(p)})'>Edit</span><span class="delete" onclick="del('printer', ${p.id})">Delete</span></td></tr>`;
            });
            document.getElementById('print-tbody').innerHTML = html;

            html = '';
            sd.users.forEach(u => html += `<tr><td style="font-weight:bold;">${u.username}</td><td>${u.role_name}</td><td class="action-links"><span class="edit" onclick='promptStaff(${JSON.stringify(u)})'>Edit</span><span class="delete" onclick="del('staff', ${u.id})">Delete</span></td></tr>`);
            document.getElementById('staff-tbody').innerHTML = html;

            // RENDERING TIMESHEETS
            html = '';
            sd.timesheets.forEach(t => {
                const clockIn = new Date(t.clock_in).toLocaleString();
                const clockOut = t.clock_out ? new Date(t.clock_out).toLocaleString() : '<span style="color:green; font-weight:bold;">Clocked In Now</span>';
                const hours = t.hours_worked ? parseFloat(t.hours_worked).toFixed(2) + ' hrs' : '-';
                html += `<tr><td style="font-weight:bold;">${t.username}</td><td>${clockIn}</td><td>${clockOut}</td><td style="color:var(--brand); font-weight:bold;">${hours}</td></tr>`;
            });
            document.getElementById('ts-tbody').innerHTML = html;

            // RENDERING AUDIT LOGS
            html = '';
            sd.audit_logs.forEach(a => {
                let formattedDetails = '';
                try { 
                    const d = JSON.parse(a.details); 
                    formattedDetails = Object.entries(d).map(([k, v]) => `<b>${k}</b>: ${v}`).join(' | ');
                } catch(e) { formattedDetails = a.details; }
                
                html += `<tr>
                    <td style="white-space:nowrap;">${new Date(a.created_at).toLocaleString()}</td>
                    <td style="font-weight:bold;">${a.username}</td>
                    <td style="text-transform:uppercase; color:var(--brand-dark); font-size:0.85rem; font-weight:bold;">${a.action_type.replace('_', ' ')}</td>
                    <td style="text-transform:capitalize;">${a.target_type} #${a.target_id}</td>
                    <td style="font-size:0.85rem; color:var(--text-muted);">${formattedDetails}</td>
                </tr>`;
            });
            document.getElementById('audit-tbody').innerHTML = html;
        }

        // --- PROMPTS ---
        async function promptCategory(obj = null) {
            const isEdit = obj !== null;
            let modHtml = '';
            sd.modifiers.forEach(m => {
                const isChecked = (isEdit && obj.modifiers.includes(parseInt(m.id))) ? 'checked' : '';
                modHtml += `<label style="display:block; text-align:left; padding:5px;"><input type="checkbox" class="cat-mod-cb" value="${m.id}" ${isChecked}> ${m.name}</label>`;
            });

            const { value: form } = await Swal.fire({
                title: isEdit ? 'Edit Category' : 'New Category',
                html: `
                    <input id="sw-name" class="swal2-input" placeholder="Name" value="${isEdit ? obj.name : ''}">
                    <select id="sw-type" class="swal2-input"><option value="food" ${isEdit&&obj.cat_type==='food'?'selected':''}>Food</option><option value="drink" ${isEdit&&obj.cat_type==='drink'?'selected':''}>Drink</option></select>
                    <div style="margin-top:15px; font-weight:bold; text-align:left;">Global Modifiers (Applies to all products here):</div>
                    <div style="border:1px solid #ddd; padding:10px; border-radius:8px; max-height:150px; overflow-y:auto;">${modHtml}</div>
                `,
                focusConfirm: false, showCancelButton: true, confirmButtonColor: '#6B4226',
                preConfirm: () => { 
                    let selectedMods = [];
                    document.querySelectorAll('.cat-mod-cb:checked').forEach(cb => selectedMods.push(parseInt(cb.value)));
                    return { name: document.getElementById('sw-name').value, cat_type: document.getElementById('sw-type').value, modifiers: selectedMods }; 
                }
            });
            if (form) save('category', { id: isEdit ? obj.id : null, ...form });
        }

        async function promptTable(obj = null) {
            const isEdit = obj !== null;
            const { value: val } = await Swal.fire({ title: isEdit?'Edit Table':'New Table', input: 'text', inputValue: isEdit?obj.table_number:'', showCancelButton: true, confirmButtonColor: '#6B4226' });
            if (val) save('table', { id: isEdit ? obj.id : null, table_number: val });
        }

        async function promptModifier(obj = null) {
            const isEdit = obj !== null;
            const { value: form } = await Swal.fire({
                title: isEdit ? 'Edit Modifier' : 'New Modifier',
                html: `<input id="sw-name" class="swal2-input" placeholder="Name" value="${isEdit ? obj.name : ''}"><input type="number" id="sw-price" class="swal2-input" placeholder="Price" step="0.01" value="${isEdit ? obj.price : ''}">`,
                focusConfirm: false, showCancelButton: true, confirmButtonColor: '#6B4226',
                preConfirm: () => { return { name: document.getElementById('sw-name').value, price: document.getElementById('sw-price').value }; }
            });
            if (form) save('modifier', { id: isEdit ? obj.id : null, ...form });
        }

        async function promptDiscount(obj = null) {
            const isEdit = obj !== null;
            const { value: form } = await Swal.fire({
                title: isEdit ? 'Edit Discount' : 'New Discount',
                html: `
                    <input id="sw-name" class="swal2-input" placeholder="Rule Name (e.g. Senior, VIP)" value="${isEdit ? obj.name : ''}">
                    <select id="sw-type" class="swal2-input">
                        <option value="percent" ${isEdit && obj.type === 'percent' ? 'selected' : ''}>Percent (%)</option>
                        <option value="fixed" ${isEdit && obj.type === 'fixed' ? 'selected' : ''}>Flat Amount (₱)</option>
                    </select>
                    <input type="number" id="sw-val" class="swal2-input" placeholder="Value (e.g. 20)" step="0.01" value="${isEdit ? obj.value : ''}">
                    <select id="sw-tgt" class="swal2-input">
                        <option value="all" ${isEdit && obj.target_type === 'all' ? 'selected' : ''}>Apply to Whole Bill</option>
                        <option value="highest" ${isEdit && obj.target_type === 'highest' ? 'selected' : ''}>Apply to Highest Item (SC/PWD Rule)</option>
                    </select>
                `,
                focusConfirm: false, showCancelButton: true, confirmButtonColor: '#6B4226',
                preConfirm: () => { 
                    return { 
                        name: document.getElementById('sw-name').value, 
                        type: document.getElementById('sw-type').value, 
                        value: document.getElementById('sw-val').value, 
                        target_type: document.getElementById('sw-tgt').value 
                    }; 
                }
            });
            if (form) save('discount', { id: isEdit ? obj.id : null, ...form });
        }

        async function promptStaff(obj = null) {
            const isEdit = obj !== null;
            let rolesHtml = ''; sd.roles.forEach(r => rolesHtml += `<option value="${r.id}" ${isEdit&&obj.role_id==r.id?'selected':''}>${r.role_name.toUpperCase()}</option>`);
            const { value: form } = await Swal.fire({
                title: isEdit ? 'Edit Staff' : 'New Staff',
                html: `
                    <input id="sw-name" class="swal2-input" placeholder="Username (Login)" value="${isEdit ? obj.username : ''}">
                    <input id="sw-first" class="swal2-input" placeholder="First Name" value="${isEdit ? (obj.first_name||'') : ''}">
                    <input id="sw-last" class="swal2-input" placeholder="Last Name" value="${isEdit ? (obj.last_name||'') : ''}">
                    <select id="sw-role" class="swal2-input">${rolesHtml}</select>
                    <input type="password" id="sw-pin" class="swal2-input" placeholder="${isEdit ? 'Leave blank to keep old PIN' : 'Enter New PIN'}">
                `,
                focusConfirm: false, showCancelButton: true, confirmButtonColor: '#6B4226',
                preConfirm: () => { return { username: document.getElementById('sw-name').value, first_name: document.getElementById('sw-first').value, last_name: document.getElementById('sw-last').value, role_id: document.getElementById('sw-role').value, pin: document.getElementById('sw-pin').value }; }
            });
            if (form) save('staff', { id: isEdit ? obj.id : null, ...form });
        }

        async function promptPrinter(obj = null) {
            const isEdit = obj !== null;
            const beepChecked = isEdit && obj.beep_on_print == 0 ? '' : 'checked';
            const cutChecked = isEdit && obj.cut_after_print == 0 ? '' : 'checked';
            const is80mm = isEdit && obj.character_limit == 48 ? 'selected' : '';
            const is58mm = isEdit && obj.character_limit == 32 ? 'selected' : (!isEdit ? 'selected' : '');

            const { value: form } = await Swal.fire({
                title: isEdit ? 'Edit Printer' : 'New Printer',
                html: `
                    <input id="sw-name" class="swal2-input" placeholder="Printer Name (e.g. Kitchen Epson)" value="${isEdit ? obj.name : ''}">
                    <select id="sw-type" class="swal2-input">
                        <option value="network" ${isEdit&&obj.connection_type==='network'?'selected':''}>Network (LAN/WiFi)</option>
                        <option value="windows" ${isEdit&&obj.connection_type==='windows'?'selected':''}>USB (Windows Driver)</option>
                    </select>
                    <input id="sw-path" class="swal2-input" placeholder="IP Address or Shared Name" value="${isEdit ? obj.path : ''}">
                    <select id="sw-size" class="swal2-input" style="margin-top:15px; font-weight:bold;">
                        <option value="48" ${is80mm}>80mm Paper (Wide - 48 chars)</option>
                        <option value="32" ${is58mm}>58mm Paper (Narrow - 32 chars)</option>
                    </select>
                    <div style="text-align:left; margin-top:15px; padding: 10px; background:#f9fafb; border:1px solid #eee; border-radius:8px;">
                        <label style="display:block; margin-bottom:10px; font-weight:bold; cursor:pointer;">
                            <input type="checkbox" id="sw-beep" ${beepChecked} style="width:auto; margin-right:10px;"> Beep on Print (Alarm)
                        </label>
                        <label style="display:block; font-weight:bold; cursor:pointer;">
                            <input type="checkbox" id="sw-cut" ${cutChecked} style="width:auto; margin-right:10px;"> Cut Paper After Print
                        </label>
                    </div>
                `,
                focusConfirm: false, showCancelButton: true, confirmButtonColor: '#6B4226',
                preConfirm: () => { 
                    return { 
                        name: document.getElementById('sw-name').value, connection_type: document.getElementById('sw-type').value, 
                        path: document.getElementById('sw-path').value, character_limit: parseInt(document.getElementById('sw-size').value),
                        beep: document.getElementById('sw-beep').checked ? 1 : 0, cut: document.getElementById('sw-cut').checked ? 1 : 0
                    }; 
                }
            });
            if (form) save('printer', { id: isEdit ? obj.id : null, ...form });
        }

        // --- CORE LOGIC ---
        async function save(type, payload) {
            const res = await fetch('../api/settings_action.php', { method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken()}, body: JSON.stringify({ action: `save_${type}`, ...payload }) });
            const data = await res.json();
            if (data.success) { Swal.fire({icon: 'success', title: 'Saved', timer: 1000, showConfirmButton: false}); loadData(); } 
            else Swal.fire('Error', data.error, 'error');
        }

        function del(type, id) {
            Swal.fire({ title: 'Delete?', text: "You can't undo this!", icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' })
            .then(async (result) => {
                if (result.isConfirmed) {
                    const res = await fetch('../api/settings_action.php', { method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken()}, body: JSON.stringify({ action: `delete_${type}`, id: id }) });
                    const data = await res.json();
                    if (data.success) { Swal.fire({icon: 'success', title: 'Deleted', timer: 1000, showConfirmButton: false}); loadData(); } 
                    else Swal.fire('Error', data.error, 'error');
                }
            });
        }

        async function saveSystemBatch(keys) {
            let payload = {};
            keys.forEach(k => { payload[k] = document.getElementById('s_' + k).value; });
            const res = await fetch('../api/settings_action.php', { method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken()}, body: JSON.stringify({ action: 'save_settings_batch', settings: payload }) });
            const data = await res.json();
            if (data.success) { Swal.fire({icon: 'success', title: 'Updated Successfully', timer: 1000, showConfirmButton: false}); loadData(); }
        }
        async function generatePayroll() {
            const start = document.getElementById('pr-start').value;
            const end = document.getElementById('pr-end').value;

            if (!start || !end) return Swal.fire('Error', 'Please select both start and end dates.', 'warning');

            document.getElementById('pr-date-label').innerText = `Period: ${start} to ${end}`;
            
            const res = await fetch('../api/settings_action.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken()},
                body: JSON.stringify({ action: 'get_payroll', start_date: start, end_date: end })
            });
            const data = await res.json();

            if (data.success) {
                let staffMap = {};

                // 1. Group shifts and split Regular vs Overtime
                data.records.forEach(r => {
                    const uid = r.user_id;
                    const name = (r.first_name || r.username) + ' ' + (r.last_name || '');
                    const rate = parseFloat(r.hourly_rate) || 0;
                    const shiftHrs = parseFloat(r.hours_worked) || 0;
                    
                    if (!staffMap[uid]) {
                        staffMap[uid] = { name: name, rate: rate, shifts: 0, reg: 0, ot: 0 };
                    }

                    staffMap[uid].shifts += 1;
                    
                    // SMART CAPPING: Max 9 hours regular, the rest is OT
                    if (shiftHrs > 9) {
                        staffMap[uid].reg += 9;
                        staffMap[uid].ot += (shiftHrs - 9);
                    } else {
                        staffMap[uid].reg += shiftHrs;
                    }
                });

                // 2. Build the UI
                let html = '';
                let totalPayout = 0;

                Object.values(staffMap).forEach(s => {
                    // Assuming you pay the same rate for OT. You can do (s.ot * s.rate * 1.25) if you pay extra for OT!
                    const regPay = s.reg * s.rate;
                    const otPay = s.ot * s.rate; 
                    const gross = regPay + otPay;
                    totalPayout += gross;

                    html += `
                        <tr>
                            <td style="font-weight:bold;">${s.name} <br><small style="color:gray;">₱${s.rate.toFixed(2)}/hr</small></td>
                            <td style="text-align:center;">${s.shifts}</td>
                            <td style="text-align:center;">${s.reg.toFixed(2)}h</td>
                            <td style="text-align:center; color:#d32f2f; font-weight:bold;">${s.ot > 0 ? s.ot.toFixed(2) + 'h' : '-'}</td>
                            <td style="text-align:right; font-weight:bold;">₱${gross.toFixed(2)}</td>
                            <td></td> <td style="text-align:right;"></td> <td style="text-align:center;"><span class="sign-line"></span></td>
                        </tr>
                    `;
                });

                if (Object.keys(staffMap).length === 0) {
                    html = `<tr><td colspan="8" style="text-align:center; padding:20px;">No shifts found for this date range.</td></tr>`;
                }

                document.getElementById('pr-tbody').innerHTML = html;
                document.getElementById('print-payroll-area').style.display = 'block'; // Make it visible to the screen and printer
            }
        }
    </script>
</body>
</html>