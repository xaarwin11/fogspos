<?php
require_once '../db.php';
session_start();

// 1. ACCESS CONTROL
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    header("Location: ../pos/index.php"); 
    exit; 
}

// 2. SECURITY: Generate CSRF token if it doesn't exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$mysqli = get_db_conn();

// 3. PRE-FETCH DATA (to avoid multiple loops)
$cats_res = $mysqli->query("SELECT * FROM categories ORDER BY sort_order ASC");
$categories = $cats_res->fetch_all(MYSQLI_ASSOC);

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
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .admin-layout { display: grid; grid-template-columns: 350px 1fr; gap: 20px; max-width: 1400px; margin: 20px auto; padding: 0 15px; height: calc(100vh - 100px); }
        @media (max-width: 900px) { .admin-layout { grid-template-columns: 1fr; height: auto; } }

        .list-panel { background: white; border-radius: 12px; border: 1px solid var(--border); display: flex; flex-direction: column; overflow: hidden; box-shadow: var(--shadow); }
        .list-header { padding: 15px; border-bottom: 1px solid var(--border); background: #f8fafc; }
        .search-box { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd; margin-top: 10px; outline: none; }
        
        .product-list { overflow-y: auto; flex: 1; }
        .list-item { padding: 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: 0.1s; display: flex; justify-content: space-between; align-items: center; }
        .list-item:hover { background: #fdfaf6; }
        .list-item.active { background: #fff1f2; border-left: 4px solid var(--brand); }
        
        .editor-panel { background: white; border-radius: 12px; border: 1px solid var(--border); padding: 25px; overflow-y: auto; box-shadow: var(--shadow); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-weight: 700; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; }
        .form-control { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; outline: none; }

        .var-section { background: #f9fafb; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin: 20px 0; }
        .var-table { width: 100%; border-collapse: collapse; }
        .var-input { padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%; }

        .mod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-top: 10px; }
        .mod-card { border: 1px solid var(--border); padding: 10px; border-radius: 8px; display: flex; align-items: center; gap: 8px; cursor: pointer; }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="admin-layout">
        
        <div class="list-panel">
            <div class="list-header">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0;">Menu Items</h3>
                    <button class="btn success small" onclick="newProduct()">+ Add New</button>
                </div>
                <input type="text" id="pSearch" class="search-box" placeholder="🔍 Search menu..." onkeyup="renderList()">
            </div>
            <div class="product-list" id="productList">
                <div style="padding:40px; text-align:center; color:#999;">Loading...</div>
            </div>
        </div>

        <div class="editor-panel">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
                <div>
                    <h2 id="editorTitle" style="margin:0; color:var(--brand-dark);">New Product</h2>
                    <p id="editorSub" style="color:gray; margin:5px 0 0 0;">Configure pricing and variations.</p>
                </div>
                <button type="button" class="btn danger" id="btnDelete" style="display:none;" onclick="deleteCurrent()">🗑️ Delete</button>
            </div>
            
            <form id="productForm">
                <input type="hidden" name="id" id="prodId">
                
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
                    <div class="form-group">
                        <label>Item Name</label>
                        <input type="text" id="prodName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
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
                                <th>Size Name</th>
                                <th width="120">Price (₱)</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody id="varBody"></tbody>
                    </table>

                    <div id="basePriceContainer" style="margin-top:15px; padding-top:15px; border-top:1px dashed #cbd5e1;">
                        <label>Base Price (Single Size)</label>
                        <input type="number" id="prodPrice" class="form-control" style="width:180px;" placeholder="0.00" step="0.01">
                    </div>
                </div>

                <div class="form-group">
                    <label>Allowed Add-ons</label>
                    <div class="mod-grid">
                        <?php foreach($modifiers as $m): ?>
                        <label class="mod-card">
                            <input type="checkbox" name="modifiers" value="<?= $m['id'] ?>" class="mod-cb">
                            <span style="font-size:0.85rem;"><?= htmlspecialchars($m['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:40px; text-align:right; border-top:1px solid #eee; padding-top:20px;">
                    <button type="button" class="btn secondary" onclick="newProduct()" style="margin-right:10px;">Discard</button>
                    <button type="submit" class="btn" style="background:var(--brand-dark); color:white; padding:12px 40px; font-weight:bold;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let allProducts = [];

        // SECURITY: Helper to get token for the API
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        }

        document.addEventListener('DOMContentLoaded', loadProducts);

        function loadProducts() {
            fetch('../api/get_products.php')
            .then(r => r.json())
            .then(data => {
                if(data.success) { allProducts = data.products; renderList(); }
            });
        }

        function renderList() {
            const list = document.getElementById('productList');
            const search = document.getElementById('pSearch').value.toLowerCase();
            list.innerHTML = '';
            
            const filtered = allProducts.filter(p => p.name.toLowerCase().includes(search));

            filtered.forEach(p => {
                const item = document.createElement('div');
                item.className = 'list-item';
                item.innerHTML = `
                    <div>
                        <div style="font-weight:700;">${p.name}</div>
                        <div style="font-size:0.75rem; color:gray;">${p.category_name}</div>
                    </div>
                    <div style="text-align:right; font-weight:bold; color:var(--brand);">
                        ₱${parseFloat(p.price).toFixed(2)}
                    </div>
                `;
                item.onclick = () => editProduct(p, item);
                list.appendChild(item);
            });
        }

        function newProduct() {
            document.getElementById('productForm').reset();
            document.getElementById('prodId').value = '';
            document.getElementById('varBody').innerHTML = '';
            document.getElementById('btnDelete').style.display = 'none';
            document.getElementById('basePriceContainer').style.display = 'block';
            document.querySelectorAll('.mod-cb').forEach(c => c.checked = false);
            document.querySelectorAll('.list-item').forEach(i => i.classList.remove('active'));
        }

        function editProduct(p, el) {
            newProduct();
            el.classList.add('active');
            document.getElementById('prodId').value = p.id;
            document.getElementById('prodName').value = p.name;
            document.getElementById('prodCat').value = p.category_id;
            document.getElementById('prodPrice').value = p.price;
            document.getElementById('btnDelete').style.display = 'block';

            if(p.variations && p.variations.length > 0) {
                p.variations.forEach(v => addVariationRow(v.name, v.price, v.id));
                document.getElementById('basePriceContainer').style.display = 'none';
            }

            document.querySelectorAll('.mod-cb').forEach(c => {
                c.checked = p.modifiers && p.modifiers.includes(parseInt(c.value));
            });
        }

        function addVariationRow(name = '', price = '', id = '') {
            const tr = document.createElement('tr');
            // We store the variation ID in a data attribute to maintain the "Smart Sync"
            tr.innerHTML = `
                <td style="padding:5px 5px 5px 0;"><input type="text" class="var-input v-name" value="${name}" data-vid="${id}"></td>
                <td style="padding:5px;"><input type="number" class="var-input v-price" value="${price}" step="0.01"></td>
                <td style="text-align:right;"><button type="button" class="btn secondary small" onclick="this.closest('tr').remove(); checkVars();">✕</button></td>
            `;
            document.getElementById('varBody').appendChild(tr);
            document.getElementById('basePriceContainer').style.display = 'none';
        }

        function checkVars() {
            if(document.getElementById('varBody').children.length === 0) document.getElementById('basePriceContainer').style.display = 'block';
        }

        document.getElementById('productForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.disabled = true;

            const vars = [];
            document.querySelectorAll('#varBody tr').forEach(tr => {
                const v_name = tr.querySelector('.v-name').value;
                const v_id = tr.querySelector('.v-name').dataset.vid; // Maintaining existing IDs
                if(v_name) vars.push({ id: v_id, name: v_name, price: tr.querySelector('.v-price').value });
            });

            const payload = {
                id: document.getElementById('prodId').value,
                name: document.getElementById('prodName').value,
                category_id: document.getElementById('prodCat').value,
                price: document.getElementById('prodPrice').value,
                variations: vars,
                modifiers: Array.from(document.querySelectorAll('.mod-cb:checked')).map(c => c.value)
            };

            // FIX: Sending the X-CSRF-Token header
            fetch('../api/save_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': getCsrfToken()
                },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire({ icon: 'success', title: 'Saved', timer: 1000, showConfirmButton: false });
                    loadProducts();
                } else { Swal.fire('Error', data.error, 'error'); }
            })
            .finally(() => btn.disabled = false);
        });

        function deleteCurrent() {
            const id = document.getElementById('prodId').value;
            Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true }).then((res) => {
                if(res.isConfirmed) {
                    fetch('../api/delete_product.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                        body: JSON.stringify({id: id})
                    }).then(() => { newProduct(); loadProducts(); });
                }
            });
        }
    </script>
</body>
</html>