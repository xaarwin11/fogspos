<?php
require_once '../db.php'; 
header('Content-Type: application/json');

try {
    $mysqli = get_db_conn();

    // 1. Fetch Modifiers (Create a Lookup Map)
    $mod_res = $mysqli->query("SELECT id, name, price FROM modifiers WHERE is_active = 1");
    $all_mods = [];
    while ($row = $mod_res->fetch_assoc()) {
        $all_mods[$row['id']] = ['id' => $row['id'], 'name' => $row['name'], 'price' => (float)$row['price']];
    }

    // 2. Fetch Variations (Grouped by Product ID)
    $var_res = $mysqli->query("SELECT id, product_id, name, price FROM product_variations ORDER BY sort_order ASC");
    $variations = [];
    while ($row = $var_res->fetch_assoc()) {
        $variations[$row['product_id']][] = ['id' => $row['id'], 'name' => $row['name'], 'price' => (float)$row['price']];
    }

    // 3. Fetch Category Modifiers (Grouped by Category ID)
    $cm_res = $mysqli->query("SELECT category_id, modifier_id FROM category_modifiers");
    $cat_mods = [];
    while ($row = $cm_res->fetch_assoc()) {
        $cat_mods[$row['category_id']][] = $row['modifier_id'];
    }

    // 4. Fetch Product Modifiers (Grouped by Product ID)
    $pm_res = $mysqli->query("SELECT product_id, modifier_id FROM product_modifiers");
    $prod_mods = [];
    while ($row = $pm_res->fetch_assoc()) {
        $prod_mods[$row['product_id']][] = $row['modifier_id'];
    }

    // 5. Fetch Categories
    $cat_res = $mysqli->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY sort_order ASC");
    $categories = [];
    while ($row = $cat_res->fetch_assoc()) {
        $categories[$row['id']] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'products' => []
        ];
    }

    // 6. Fetch Products & Assemble Everything
    $prod_res = $mysqli->query("SELECT id, category_id, name, price, image_url FROM products WHERE available = 1 ORDER BY sort_order ASC, name ASC");
    while ($row = $prod_res->fetch_assoc()) {
        $pid = $row['id'];
        $cid = $row['category_id'];
        $row['price'] = (float)$row['price'];
        
        // Attach Variations (Sizes)
        $row['variations'] = $variations[$pid] ?? [];
        
        // Attach Modifiers (Merge Category-level + Product-level, then remove duplicates)
        $mod_ids = array_unique(array_merge($cat_mods[$cid] ?? [], $prod_mods[$pid] ?? []));
        $row['modifiers'] = [];
        
        foreach ($mod_ids as $mid) {
            if (isset($all_mods[$mid])) {
                $row['modifiers'][] = $all_mods[$mid]; // Injects Name and Price!
            }
        }
        
        // Add the fully-loaded product to its category
        if (isset($categories[$cid])) {
            $categories[$cid]['products'][] = $row;
        }
    }

    // 7. Filter out categories that have no active products
    $categories = array_filter($categories, function($c) { 
        return count($c['products']) > 0; 
    });

    echo json_encode(['success' => true, 'menu' => array_values($categories)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>