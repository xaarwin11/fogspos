<?php
require_once '../db.php';
session_start();
if (empty($_SESSION['user_id'])) { header("Location: ../index.php"); exit; }

$mysqli = get_db_conn();

// 1. Fetch Categories for Dropdown
$cats = $mysqli->query("SELECT * FROM categories ORDER BY sort_order ASC");

// 2. Fetch All Modifiers (to show checkboxes)
$mods = $mysqli->query("SELECT * FROM modifiers WHERE is_active = 1 ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Menu Manager - FogsTasa</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        /* ADMIN LAYOUT: Split Screen (List vs Editor) */
        .admin-layout { 
            display: grid; 
            grid-template-columns: 320px 1fr; 
            gap: 25px; 
            max-width: 1400px; 
            margin: 25px auto; 
            padding: 0 20px; 
            height: calc(100vh - 100px); 
        }
        
        /* LEFT PANEL: Product List */
        .list-panel { 
            background: white; 
            border-radius: 12px; 
            border: 1px solid var(--border); 
            display: flex; 
            flex-direction: column; 
            overflow: hidden; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }
        .list-header { 
            padding: 15px; 
            border-bottom: 1px solid var(--border); 
            background: #f8fafc; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
        }
        .product-list { 
            overflow-y: auto; 
            flex: 1; 
        }
        .list-item { 
            padding: 15px; 
            border-bottom: 1px solid #f1f5f9; 
            cursor: pointer; 
            transition: 0.1s; 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
        }
        .list-item:hover { background: #fdf2f8; } /* Light pink hover */
        .list-item.active { 
            background: #fff1f2; 
            border-left: 4px solid var(--brand); 
        }
        .item-meta { font-size: 0.85rem; color: var(--text-muted); display: block; margin-top: 2px; }

        /* RIGHT PANEL: Editor */
        .editor-panel { 
            background: white; 
            border-radius: 12px; 
            border: 1px solid var(--border); 
            padding: 30px; 
            overflow-y: auto; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        /* Form Elements */
        label { display: block; margin-bottom: 6px; font-weight: 600; color: var(--text-muted); font-size: 0.9rem; }
        .form-control { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; margin-bottom: 15px; }
        .form-control:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(107, 66, 38, 0.1); }

        /* Variations Table */
        .var-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 15px; margin-bottom: 20px; }
        .var-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .var-table th { text-align: left; font-size: 0.8rem; color: var(--text-muted); border-bottom: 1px solid #ddd; padding: 5px; }
        .var-table td { padding: 5px 0; }
        .var-input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; }

        /* Modifier Grid */
        .mod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; margin-top: 10px; }
        .mod-check { 
            display: flex; align-items: center; gap: 8px; padding: 10px; 
            border: 1px solid var(--border); border-radius: 8px; cursor: pointer; transition: 0.1s;
        }
        .mod-check:hover { background: #fafafa; border-color: var(--brand-light); }
        .mod-check input:checked + span { font-weight: 700; color: var(--brand); }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="admin-layout">
        
        <div class="list-panel">
            <div class="list-header">
                <span style="font-weight:700; color:var(--text-main);">All Products</span>
                <button class="btn small" onclick="newProduct()">+ New</button>
            </div>
            <div class="product-list" id="productList">
                <div style="padding:20px; text-align:center; color:#999;">Loading...</div>
            </div>
        </div>

        <div class="editor-panel">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 id="editorTitle" style="margin:0; color:var(--brand);">New Product</h2>
                <button type="button" class="btn danger" id="btnDelete" style="display:none; padding:8px 15px;" onclick="deleteCurrent()">
                    Trash Icon 🗑️
                </button>
            </div>
            
            <form id="productForm">
                <input type="hidden" name="id" id="prodId">
                
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
                    <div>
                        <label>Product Name</label>
                        <input type="text" id="prodName" class="form-control" required placeholder="e.g. Caramel Macchiato">
                    </div>
                    <div>
                        <label>Category</label>
                        <select id="prodCat" class="form-control">
                            <?php while($c = $cats->fetch_assoc()): ?>
                                <option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="var-box">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label style="margin:0;">Variations / Sizes</label>
                        <button type="button" class="btn secondary small" onclick="addVariationRow()">+ Add Row</button>
                    </div>
                    <p style="font-size:0.8rem; color:#666; margin:5px 0 10px;">
                        <i>Use this for Sizes (Regular, Large) or Types (Hot, Iced). If added, Base Price is ignored.</i>
                    </p>
                    
                    <table class="var-table">
                        <thead>
                            <tr>
                                <th>Variation Name</th>
                                <th width="120">Price</th>
                                <th width="40"></th>
                            </tr>
                        </thead>
                        <tbody id="varBody">
                            </tbody>
                    </table>

                    <div id="basePriceGroup" style="margin-top:15px; border-top:1px dashed #ddd; padding-top:15px;">
                        <label>Base Price (Single Size)</label>
                        <input type="number" id="prodPrice" class="form-control" style="width:150px; margin-bottom:0;" placeholder="0.00">
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <label>Allowed Modifiers (Add-ons)</label>
                    <div class="mod-grid">
                        <?php while($m = $mods->fetch_assoc()): ?>
                        <label class="mod-check">
                            <input type="checkbox" name="modifiers" value="<?= $m['id'] ?>" class="mod-checkbox">
                            <span><?= $m['name'] ?> (+<?= $m['price'] ?>)</span>
                        </label>
                        <?php endwhile; ?>
                    </div>
                </div>

                <div style="margin-top:30px; border-top:1px solid #eee; padding-top:20px; text-align:right;">
                    <button type="button" class="btn secondary" onclick="newProduct()" style="margin-right:10px;">Cancel</button>
                    <button type="submit" class="btn success" style="padding: 12px 30px; font-size:1rem;">Save Product</button>
                </div>
            </form>
        </div>

    </div>

    <script>
        // --- GLOBAL STATE ---
        let allProducts = [];

        // --- INIT ---
        document.addEventListener('DOMContentLoaded', loadProducts);

        // --- 1. LOAD FULL DATA ---
        function loadProducts() {
            fetch('../api/get_products.php') 
            .then(r => r.json())
            .then(data => {
                allProducts = data.products;
                renderList();
            })
            .catch(err => console.error(err));
        }

        // --- 2. RENDER LIST ---
        function renderList() {
            const list = document.getElementById('productList');
            list.innerHTML = '';
            
            if(allProducts.length === 0) {
                list.innerHTML = '<div style="padding:20px; text-align:center;">No products yet.</div>';
                return;
            }

            allProducts.forEach(p => {
                const item = document.createElement('div');
                item.className = 'list-item';
                // Show variation count badge if any
                const varBadge = p.variations.length > 0 ? `<span class="badge">${p.variations.length} Sizes</span>` : '';
                
                item.innerHTML = `
                    <div>
                        <div style="font-weight:600;">${p.name}</div>
                        <span class="item-meta">${p.category_name}</span>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:bold; color:var(--brand);">₱${parseFloat(p.price).toFixed(2)}</div>
                        ${varBadge}
                    </div>
                `;
                item.onclick = () => editProduct(p, item);
                list.appendChild(item);
            });
        }

        // --- 3. EDITOR ACTIONS ---
        function newProduct() {
            // Reset Form
            document.getElementById('productForm').reset();
            document.getElementById('prodId').value = '';
            document.getElementById('editorTitle').innerText = 'New Product';
            document.getElementById('varBody').innerHTML = '';
            document.getElementById('btnDelete').style.display = 'none';
            document.getElementById('basePriceGroup').style.display = 'block';
            
            // Clear Modifiers
            document.querySelectorAll('.mod-checkbox').forEach(c => c.checked = false);
            
            // Clear Active List Item
            document.querySelectorAll('.list-item').forEach(i => i.classList.remove('active'));
        }

        function editProduct(p, itemEl) {
            // Highlight in List
            document.querySelectorAll('.list-item').forEach(i => i.classList.remove('active'));
            if(itemEl) itemEl.classList.add('active');

            // Populate Fields
            document.getElementById('prodId').value = p.id;
            document.getElementById('prodName').value = p.name;
            document.getElementById('prodCat').value = p.category_id;
            document.getElementById('prodPrice').value = p.price;
            document.getElementById('editorTitle').innerText = 'Editing: ' + p.name;
            document.getElementById('btnDelete').style.display = 'block';

            // Populate Variations
            const tbody = document.getElementById('varBody');
            tbody.innerHTML = '';
            if(p.variations && p.variations.length > 0) {
                p.variations.forEach(v => addVariationRow(v.name, v.price));
                document.getElementById('basePriceGroup').style.display = 'none'; // Hide base price if vars exist
            } else {
                document.getElementById('basePriceGroup').style.display = 'block';
            }

            // Populate Modifiers
            document.querySelectorAll('.mod-checkbox').forEach(c => c.checked = false);
            if(p.modifiers) {
                p.modifiers.forEach(mid => {
                    const cb = document.querySelector(`.mod-checkbox[value="${mid}"]`);
                    if(cb) cb.checked = true;
                });
            }
        }

        function addVariationRow(name = '', price = '') {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><input type="text" class="var-input var-name" placeholder="Size/Type" value="${name}"></td>
                <td><input type="number" class="var-input var-price" placeholder="0.00" value="${price}"></td>
                <td><button type="button" class="btn secondary small" onclick="removeRow(this)" style="color:var(--danger); border:none;">✕</button></td>
            `;
            document.getElementById('varBody').appendChild(tr);
            
            // Hide base price because we are using vars now
            document.getElementById('basePriceGroup').style.display = 'none';
        }

        function removeRow(btn) {
            btn.closest('tr').remove();
            // Show base price if no rows left
            if(document.getElementById('varBody').children.length === 0) {
                document.getElementById('basePriceGroup').style.display = 'block';
            }
        }

        // --- 4. SAVE ---
        document.getElementById('productForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            btn.innerText = "Saving..."; btn.disabled = true;

            // Gather Variations
            let vars = [];
            document.querySelectorAll('#varBody tr').forEach(tr => {
                let name = tr.querySelector('.var-name').value;
                let price = tr.querySelector('.var-price').value;
                if(name && price) vars.push({name, price});
            });

            // Gather Modifiers
            let mods = [];
            document.querySelectorAll('.mod-checkbox:checked').forEach(cb => mods.push(cb.value));

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
                    Swal.fire({ icon: 'success', title: 'Saved!', timer: 1000, showConfirmButton: false });
                    loadProducts();
                    if(!payload.id) newProduct(); // Clear if new
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            })
            .finally(() => { btn.innerText = "Save Product"; btn.disabled = false; });
        });

        // --- 5. DELETE ---
        function deleteCurrent() {
            const id = document.getElementById('prodId').value;
            if(!id) return;

            Swal.fire({
                title: 'Delete Product?',
                text: "This cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Delete'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('../api/delete_product.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({id: id})
                    })
                    .then(r => r.json())
                    .then(data => {
                        if(data.success) {
                            Swal.fire('Deleted!', '', 'success');
                            newProduct();
                            loadProducts();
                        } else {
                            Swal.fire('Error', data.error, 'error');
                        }
                    });
                }
            })
        }
    </script>
</body>
</html>