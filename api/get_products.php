<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// Allow either logged-in staff OR the public menu
if (empty($_SESSION['user_id']) && empty($_SESSION['public_csrf'])) { 
    echo json_encode(['success' => false, 'error' => 'Unauthorized']); 
    exit; 
}

try {
    $mysqli = get_db_conn();

    $res = $mysqli->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.available = 1 ORDER BY p.sort_order ASC, p.name ASC");
    $products = [];
    while($row = $res->fetch_assoc()) {
        $row['price'] = (float)$row['price'];
        $row['category_id'] = (int)$row['category_id'];
        $row['variations'] = [];
        $row['modifiers'] = []; 
        $products[$row['id']] = $row;
    }

    // FIX: Force variations to ALWAYS order by price from lowest to highest!
    $v_res = $mysqli->query("SELECT * FROM product_variations ORDER BY price ASC");
    while($v = $v_res->fetch_assoc()) {
        if(isset($products[$v['product_id']])) {
            $v['price'] = (float)$v['price'];
            $products[$v['product_id']]['variations'][] = $v;
        }
    }

    $pm_res = $mysqli->query("SELECT product_id, modifier_id FROM product_modifiers");
    while($pm = $pm_res->fetch_assoc()) {
        if(isset($products[$pm['product_id']])) {
            $products[$pm['product_id']]['modifiers'][] = (int)$pm['modifier_id'];
        }
    }

    $cm_res = $mysqli->query("SELECT category_id, modifier_id FROM category_modifiers");
    while($cm = $cm_res->fetch_assoc()) {
        $cid = (int)$cm['category_id'];
        $mid = (int)$cm['modifier_id'];
        foreach ($products as $p_id => $p) {
            if ($p['category_id'] === $cid && !in_array($mid, $products[$p_id]['modifiers'])) {
                $products[$p_id]['modifiers'][] = $mid;
            }
        }
    }

    // Clean the arrays so JS reads them perfectly
    foreach ($products as &$p) { $p['modifiers'] = array_values(array_unique($p['modifiers'])); }

    $all_mods = $mysqli->query("SELECT id, name, price FROM modifiers WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
    $discounts = $mysqli->query("SELECT * FROM discounts WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
    $categories = $mysqli->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order ASC")->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'success' => true, 'products' => array_values($products),
        'modifiers' => $all_mods, 'discounts' => $discounts, 'categories' => $categories
    ]);
    exit; 
} catch (Exception $e) {
    http_response_code(500); echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
}
?>