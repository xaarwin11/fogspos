<?php
require_once '../db.php';
session_start();

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ACCESS CONTROL: Restricted to Admin/Manager
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    header("Location: ../pos/index.php"); 
    exit; 
}

$mysqli = get_db_conn();

// 1. Fetch Categories for Dropdown and Filtering
$cats_res = $mysqli->query("SELECT * FROM categories ORDER BY sort_order ASC");
$categories = $cats_res->fetch_all(MYSQLI_ASSOC);

// 2. Fetch All Modifiers
$mods_res = $mysqli->query("SELECT * FROM modifiers WHERE is_active = 1 ORDER BY name ASC");
$modifiers = $mods_res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Manager - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .admin-layout { display: grid; grid-template-columns: 350px 1fr; gap: 20px; max-width: 1400px; margin: 20px auto; padding: 0 15px; height: calc(100vh - 100px); }
        @media (max-width: 900px) { .admin-layout { grid-template-columns: 1fr; height: auto; } }

        /* LEFT PANEL: SMART LIST */
        .list-panel { background: white; border-radius: 12px; border: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; box-shadow: var(--shadow); }
        .list-header { padding: 15px; border-bottom: 1px solid var(--border); background: #f8fafc; }
        .search-box { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd; margin-top: 10px; outline: none; }
        .cat-filter-tabs { display: flex; gap: 5px; overflow-x: auto; padding: 10px 15px; background: white; border-bottom: 1px solid #f1f5f9; scrollbar-width: none; }
        .cat-tab { padding: 6px 12px; border-radius: 20px; background: #f1f5f9; font-size: 0.8rem; font-weight: bold; cursor: pointer; white-space: nowrap; border: 1px solid transparent; }
        .cat-tab.active { background: var(--brand); color: white; }
        
        .product-list { overflow-y: auto; flex: 1; }
        .list-item { padding: 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: 0.1s; display: flex; justify-content: space-between; align-items: center; }
        .list-item:hover { background: #fdfaf6; }
        .list-item.active { background: #fff1f2; border-left: 4px solid var(--brand); }
        .price-tag { font-weight: 800; color: var(--brand); font-size: 1rem; }
        .price-tag.variable { color: #2563eb; font-size: 0.75rem; text-transform: uppercase; }

        /* RIGHT PANEL: EDITOR */
        .editor-panel { background: white; border-radius: 12px; border: 1px solid var(--border); padding: 25px; overflow-y: auto; box-shadow: var(--shadow); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: 700; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; outline: none; }
        .form-control:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(107, 66, 38, 0.1); }

        .var-section { background: #f9fafb; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin: 20px 0; }
        .var-table { width: 100%; border-collapse: collapse; }
        .var-table th { text-align: left; font-size: 0.75rem; color: gray; padding-bottom: 10px; }
        .var-input { padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%; }

        .mod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
        .mod-card { border: 1px solid var(--border); padding: 10px; border-radius: 8px; display: flex; align-items: center; gap: 8px; cursor: pointer; transition: 0.2s; }
        .mod-card:hover { border-color: var(--brand); background: #fdfaf6; }
        .mod-card input { transform: scale(1.2); }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="admin-layout">
        
        <div class="list-panel">
            <div class="list-header">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Menu Items</h3>
                    <button class="btn success small" onclick="newProduct()">+ Add Item</button>
                </div>
                <input type="text" id="pSearch" class="search-box" placeholder="🔍 Search product name..." onkeyup="filterList()">
            </div>
            <div class="cat-filter-tabs">
                <div class="cat-tab active" onclick="filterCat('All', this)">All</div>
                <?php foreach($categories as $c): ?>
                    <div class="cat-tab" onclick="filterCat('<?= $c['name'] ?>', this)"><?= $c['name'] ?></div>
                <?php endforeach; ?>
            </div>
            <div class="product-list" id="productList">
                <div style="padding:40px; text-align:center; color:#999;">Loading items...</div>
            </div>
        </div>

        <div class="editor-panel">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                <div>
                    <h2 id="editorTitle" style="margin:0; color:var(--brand-dark);">Create New Item</h2>
                    <p id="editorSub" style="color:gray; margin:5px 0 0 0;">Define pricing, sizes, and add-ons.</p>
                </div>
                <button type="button" class="btn danger" id="btnDelete" style="display:none;" onclick="deleteCurrent()">Delete Item</button>
            </div>
            
            <form id="productForm">
                <input type="hidden" name="id" id="prodId">
                
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
                    <div class="form-group">
                        <label>Item Display Name</label>
                        <input type="text" id="prodName" class="form-control" required placeholder="e.g. Spanish Latte">
                    </div>
                    <div class="form-group">
                        <label>Menu Category</label>
                        <select id="prodCat" class="form-control">
                            <?php foreach($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="var-section">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h4 style="margin:0;">Sizes & Variations</h4>
                        <button type="button" class="btn secondary small" onclick="addVariationRow()">+ Add Size</button>
                    </div>
                    
                    <table class="var-table">
                        <thead>
                            <tr>
                                <th>Name (e.g. 16oz, Hot)</th>
                                <th width="120">Price (₱)</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody id="varBody"></tbody>
                    </table>

                    <div id="basePriceContainer" style="margin-top:15px; padding-top:15px; border-top:1px dashed #cbd5e1;">
                        <label>Base Price (Single Size / Variable)</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <input type="number" id="prodPrice" class="form-control" style="width:180px;" placeholder="0.00" step="0.01">
                            <span style="font-size:0.8rem; color:gray; max-width:250px;">Set to <b>0.00</b> if price is decided at the POS (like Corkage).</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Applicable Add-ons (Modifiers)</label>
                    <div class="mod-grid">
                        <?php foreach($modifiers as $m): ?>
                        <label class="mod-card">
                            <input type="checkbox" name="modifiers" value="<?= $m['id'] ?>" class="mod-cb">
                            <div style="line-height:1.2;">
                                <div style="font-weight:bold; font-size:0.85rem;"><?= htmlspecialchars($m['name']) ?></div>
                                <div style="color:var(--brand); font-size:0.75rem;">+₱<?= number_format($m['price'], 2) ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:40px; text-align:right; border-top:1px solid #eee; padding-top:20px;">
                    <button type="button" class="btn secondary" onclick="newProduct()" style="margin-right:10px;">Discard Changes</button>
                    <button type="submit" class="btn" style="background:var(--brand-dark); color:white; padding:12px 40px; font-weight:bold;">Update Menu Item</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let allProducts = [];
        let currentFilterCat = 'All';

        document.addEventListener('DOMContentLoaded', loadProducts);

        function loadProducts() {
            fetch('../api/get_products.php')
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    allProducts = data.products;
                    renderList();
                }
            });
        }

        function filterCat(catName, btn) {
            currentFilterCat = catName;
            document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            renderList();
        }

        function filterList() { renderList(); }

        function renderList() {
            const list = document.getElementById('productList');
            const search = document.getElementById('pSearch').value.toLowerCase();
            list.innerHTML = '';
            
            const filtered = allProducts.filter(p => {
                const matchCat = (currentFilterCat === 'All' || p.category_name === currentFilterCat);
                const matchSearch = p.name.toLowerCase().includes(search);
                return matchCat && matchSearch;
            });

            if(filtered.length === 0) {
                list.innerHTML = '<div style="padding:40px; text-align:center; color:gray;">No items found.</div>';
                return;
            }

            filtered.forEach(p => {
                const item = document.createElement('div');
                item.className = 'list-item';
                
                const isVariable = parseFloat(p.price) === 0 && p.variations.length === 0;
                const priceDisplay = isVariable ? '<span class="price-tag variable">VARIABLE</span>' : `<span class="price-tag">₱${parseFloat(p.price).toFixed(2)}</span>`;
                const varLabel = p.variations.length > 0 ? `<div style="font-size:0.7rem; color:gray; font-weight:bold;">${p.variations.length} VARIATIONS</div>` : '';

                item.innerHTML = `
                    <div style="flex:1;">
                        <div style="font-weight:700; color:var(--text-main);">${p.name}</div>
                        <div style="font-size:0.75rem; color:gray; text-transform:uppercase; font-weight:bold;">${p.category_name}</div>
                    </div>
                    <div style="text-align:right;">
                        ${priceDisplay}
                        ${varLabel}
                    </div>
                `;
                item.onclick = () => editProduct(p, item);
                list.appendChild(item);
            });
        }

        function newProduct() {
            document.getElementById('productForm').reset();
            document.getElementById('prodId').value = '';
            document.getElementById('editorTitle').innerText = 'Create New Item';
            document.getElementById('varBody').innerHTML = '';
            document.getElementById('btnDelete').style.display = 'none';
            document.getElementById('basePriceContainer').style.display = 'block';
            document.querySelectorAll('.mod-cb').forEach(c => c.checked = false);
            document.querySelectorAll('.list-item').forEach(i => i.classList.remove('active'));
        }

        function editProduct(p, el) {
            document.querySelectorAll('.list-item').forEach(i => i.classList.remove('active'));
            el.classList.add('active');

            document.getElementById('prodId').value = p.id;
            document.getElementById('prodName').value = p.name;
            document.getElementById('prodCat').value = p.category_id;
            document.getElementById('prodPrice').value = p.price;
            document.getElementById('editorTitle').innerText = 'Editing: ' + p.name;
            document.getElementById('btnDelete').style.display = 'block';

            // Variations Logic
            const vBody = document.getElementById('varBody');
            vBody.innerHTML = '';
            if(p.variations && p.variations.length > 0) {
                p.variations.forEach(v => addVariationRow(v.name, v.price));
                document.getElementById('basePriceContainer').style.display = 'none';
            } else {
                document.getElementById('basePriceContainer').style.display = 'block';
            }

            // Modifiers Logic
            document.querySelectorAll('.mod-cb').forEach(c => {
                c.checked = p.modifiers && p.modifiers.includes(parseInt(c.value));
            });
        }

        function addVariationRow(name = '', price = '') {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="padding:5px 5px 5px 0;"><input type="text" class="var-input v-name" value="${name}" placeholder="e.g. 16oz"></td>
                <td style="padding:5px;"><input type="number" class="var-input v-price" value="${price}" placeholder="0.00" step="0.01"></td>
                <td style="text-align:right;"><button type="button" class="btn secondary small" onclick="this.closest('tr').remove(); checkVars();" style="color:red; border:none;">✕</button></td>
            `;
            document.getElementById('varBody').appendChild(tr);
            document.getElementById('basePriceContainer').style.display = 'none';
        }

        function checkVars() {
            if(document.getElementById('varBody').children.length === 0) {
                document.getElementById('basePriceContainer').style.display = 'block';
            }
        }

        document.getElementById('productForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true; btn.innerText = "Processing...";

            const vars = [];
            document.querySelectorAll('#varBody tr').forEach(tr => {
                const vn = tr.querySelector('.v-name').value;
                const vp = tr.querySelector('.v-price').value;
                if(vn) vars.push({ name: vn, price: vp });
            });

            const mods = [];
            document.querySelectorAll('.mod-cb:checked').forEach(c => mods.push(c.value));

            const payload = {
                id: document.getElementById('prodId').value,
                name: document.getElementById('prodName').value,
                category_id: document.getElementById('prodCat').value,
                price: document.getElementById('prodPrice').value,
                variations: vars,
                modifiers: mods
            };

            fetch('../api/save_product.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire({ icon: 'success', title: 'Menu Updated', timer: 1000, showConfirmButton: false });
                    loadProducts();
                    if(!payload.id) newProduct();
                } else { Swal.fire('Error', data.error, 'error'); }
            })
            .finally(() => { btn.disabled = false; btn.innerText = "Update Menu Item"; });
        });

        function deleteCurrent() {
            const id = document.getElementById('prodId').value;
            Swal.fire({
                title: 'Delete this item?',
                text: "This will remove it from the POS menu immediately.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Yes, Delete It'
            }).then((res) => {
                if(res.isConfirmed) {
                    fetch('../api/delete_product.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id})
                    }).then(() => {
                        Swal.fire('Removed', '', 'success');
                        newProduct();
                        loadProducts();
                    });
                }
            });
        }
    </script>
</body>
</html>