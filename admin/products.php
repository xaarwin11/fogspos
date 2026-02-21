<?php
require_once '../db.php';
session_start();
if (empty($_SESSION['user_id'])) { header("Location: ../index.php"); exit; }

$mysqli = get_db_conn();

$cats = $mysqli->query("SELECT * FROM categories ORDER BY sort_order ASC");
$mods = $mysqli->query("SELECT * FROM modifiers WHERE is_active = 1 ORDER BY name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Manager - FogsTasa</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon.png">
    <link rel="stylesheet" href="../assets/css/main.css">
    <script src="../assets/js/sweetalert2.js"></script>
    <style>
        .admin-layout { 
            display: grid; 
            grid-template-columns: 320px 1fr; 
            gap: 25px; 
            max-width: 1400px; 
            margin: 25px auto; 
            padding: 0 20px; 
            height: calc(100vh - 100px); 
        }
        
        .list-panel { 
            background: white; 
            border-radius: 12px; 
            border: 1px solid var(--border); 
            display: flex; flex-direction: column; 
            overflow: hidden; 
        }
        .list-header { padding: 15px; border-bottom: 1px solid var(--border); background: var(--bg-dark); }
        .list-items { overflow-y: auto; flex: 1; }
        
        .list-item { 
            padding: 12px 15px; border-bottom: 1px solid #eee; 
            cursor: pointer; display: flex; justify-content: space-between;
        }
        .list-item:hover { background: #fdfaf6; }
        .list-item.active { background: var(--brand); color: white; }
        .list-item.active .text-muted { color: #f0f0f0; }

        .editor-panel { 
            background: white; border-radius: 12px; 
            border: 1px solid var(--border); padding: 30px; 
            overflow-y: auto; 
        }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: var(--text-main); }
        .form-control { 
            width: 100%; padding: 10px 15px; border: 1px solid #ccc; 
            border-radius: 8px; font-size: 1rem; 
        }
        
        .var-row { display: grid; grid-template-columns: 1fr 100px 50px; gap: 10px; margin-bottom: 10px; }
        .mod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }

        /* --- MOBILE RESPONSIVE FIX --- */
        @media (max-width: 900px) {
            .admin-layout { 
                grid-template-columns: 1fr; 
                height: auto; 
                padding: 10px; 
                margin: 10px auto;
                gap: 15px;
            }
            .list-panel { 
                height: 350px; /* Limits the list height so it doesn't take the whole screen */
            }
            .editor-panel { 
                height: auto; 
                overflow: visible; 
                padding: 15px; 
            }
            .var-row { grid-template-columns: 1fr 80px 40px; }
        }
    </style>
</head>
<body>

    <?php include '../components/navbar.php'; ?>

    <div class="admin-layout">
        <div class="list-panel">
            <div class="list-header">
                <input type="text" id="searchMenu" class="form-control" placeholder="Search menu..." onkeyup="filterMenu()">
                <button class="btn" style="width:100%; margin-top:10px;" onclick="newProduct()">+ New Product</button>
            </div>
            <div class="list-items" id="productList">
                <div style="padding:20px; text-align:center; color:gray;">Loading...</div>
            </div>
        </div>

        <div class="editor-panel">
            <h2 id="editorTitle" style="margin-top:0; color:var(--brand-dark);">Select a Product</h2>
            
            <form id="productForm" style="display:none;" onsubmit="event.preventDefault();">
                <input type="hidden" id="prodId">
                
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="p_name" class="form-control" required>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                    <div class="form-group">
                        <label>Category</label>
                        <select id="p_category" class="form-control">
                            <?php while($c = $cats->fetch_assoc()): ?>
                                <option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Base Price (₱)</label>
                        <input type="number" id="p_price" class="form-control" step="0.01" value="0.00" required>
                    </div>
                </div>

                <div class="form-group" style="background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #eee;">
                    <label style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Sizes / Variations</span>
                        <button type="button" class="btn secondary" style="padding:5px 10px;" onclick="addVariation()">+ Add Size</button>
                    </label>
                    <p style="font-size:0.8rem; color:gray; margin-top:0;">If you add variations, the Base Price above is ignored.</p>
                    <div id="variationsContainer"></div>
                </div>

                <div class="form-group">
                    <label>Specific Add-ons / Modifiers</label>
                    <p style="font-size:0.8rem; color:gray; margin-top:0;">(Category-wide modifiers are handled in Settings > Categories)</p>
                    <div class="mod-grid">
                        <?php while($m = $mods->fetch_assoc()): ?>
                            <label style="display:flex; align-items:center; gap:8px; padding:10px; border:1px solid #eee; border-radius:6px; cursor:pointer;">
                                <input type="checkbox" class="mod-cb" value="<?= $m['id'] ?>">
                                <span><?= $m['name'] ?> <small style="color:var(--brand)">+₱<?= $m['price'] ?></small></span>
                            </label>
                        <?php endwhile; ?>
                    </div>
                </div>

                <hr style="border:0; border-top:1px solid var(--border); margin:30px 0;">

                <div style="display:flex; justify-content:space-between;">
                    <button type="button" class="btn danger" id="btnDelete" onclick="deleteCurrent()">🗑️ Delete Product</button>
                    <button type="button" class="btn success" id="btnSave" onclick="saveCurrent()" style="padding:10px 30px; font-size:1.1rem;">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let products = [];
        
        document.addEventListener('DOMContentLoaded', loadProducts);

        // --- 1. FETCH DATA ---
        function loadProducts() {
            fetch('../api/get_products.php')
                .then(r => r.json())
                .then(data => {
                    if(data.success) {
                        products = Object.values(data.products);
                        renderList();
                    }
                });
        }

        // --- 2. RENDER LIST ---
        function renderList() {
            const term = document.getElementById('searchMenu').value.toLowerCase();
            const list = document.getElementById('productList');
            list.innerHTML = '';
            
            let filtered = products.filter(p => p.name.toLowerCase().includes(term));
            
            filtered.forEach(p => {
                const isActive = document.getElementById('prodId').value == p.id ? 'active' : '';
                list.innerHTML += `
                    <div class="list-item ${isActive}" onclick="editProduct(${p.id})">
                        <span>${p.name}</span>
                        <span class="text-muted" style="font-size:0.9rem;">₱${p.price}</span>
                    </div>
                `;
            });
        }

        function filterMenu() { renderList(); }

        // --- 3. EDITOR LOGIC ---
        function newProduct() {
            document.getElementById('productForm').style.display = 'block';
            document.getElementById('editorTitle').innerText = 'Create New Product';
            document.getElementById('btnDelete').style.display = 'none';
            
            document.getElementById('prodId').value = '';
            document.getElementById('p_name').value = '';
            document.getElementById('p_price').value = '0.00';
            document.getElementById('variationsContainer').innerHTML = '';
            
            document.querySelectorAll('.mod-cb').forEach(cb => cb.checked = false);
            renderList();

            // Mobile Auto-Scroll
            if(window.innerWidth <= 900) {
                document.querySelector('.editor-panel').scrollIntoView({behavior: 'smooth'});
            }
        }

        function editProduct(id) {
            const p = products.find(x => x.id == id);
            if(!p) return;

            document.getElementById('productForm').style.display = 'block';
            document.getElementById('editorTitle').innerText = 'Edit: ' + p.name;
            document.getElementById('btnDelete').style.display = 'block';
            
            document.getElementById('prodId').value = p.id;
            document.getElementById('p_name').value = p.name;
            document.getElementById('p_category').value = p.category_id;
            document.getElementById('p_price').value = p.price;

            // Load Variations
            const vCon = document.getElementById('variationsContainer');
            vCon.innerHTML = '';
            if(p.variations) {
                p.variations.forEach(v => addVariation(v.id, v.name, v.price));
            }

            // Load Modifiers
            document.querySelectorAll('.mod-cb').forEach(cb => {
                cb.checked = p.modifiers && p.modifiers.includes(parseInt(cb.value));
            });

            renderList();

            // Mobile Auto-Scroll
            if(window.innerWidth <= 900) {
                document.querySelector('.editor-panel').scrollIntoView({behavior: 'smooth'});
            }
        }

        function addVariation(id = '', name = '', price = '') {
            const html = `
                <div class="var-row">
                    <input type="hidden" class="v_id" value="${id}">
                    <input type="text" class="form-control v_name" placeholder="Size (e.g. Large)" value="${name}">
                    <input type="number" class="form-control v_price" placeholder="Price" step="0.01" value="${price}">
                    <button type="button" class="btn danger" onclick="this.parentElement.remove()">X</button>
                </div>
            `;
            document.getElementById('variationsContainer').insertAdjacentHTML('beforeend', html);
        }

        // --- 4. SAVE ---
        function saveCurrent() {
            if(!document.getElementById('p_name').value) return Swal.fire('Error', 'Name is required', 'error');

            const btn = document.getElementById('btnSave');
            btn.innerText = "Saving..."; btn.disabled = true;

            let payload = {
                id: document.getElementById('prodId').value,
                name: document.getElementById('p_name').value,
                category_id: document.getElementById('p_category').value,
                price: document.getElementById('p_price').value,
                variations: [],
                modifiers: []
            };

            document.querySelectorAll('.var-row').forEach(row => {
                const vn = row.querySelector('.v_name').value;
                const vp = row.querySelector('.v_price').value;
                if(vn && vp) {
                    payload.variations.push({
                        id: row.querySelector('.v_id').value,
                        name: vn, price: vp
                    });
                }
            });

            document.querySelectorAll('.mod-cb:checked').forEach(cb => {
                payload.modifiers.push(parseInt(cb.value));
            });

            fetch('../api/save_product.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    Swal.fire({icon: 'success', title: 'Saved!', timer: 1000, showConfirmButton: false});
                    loadProducts();
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            })
            .finally(() => { btn.innerText = "Save Product"; btn.disabled = false; });
        }

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