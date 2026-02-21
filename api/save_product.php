<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// Security Fix: Hide errors in production
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

// Security Fix: Enforce CSRF token
$headers = getallheaders();
$csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid. Please refresh the page.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success' => false, 'error' => 'No data reached the server.']); exit; }

$id = !empty($input['id']) ? (int)$input['id'] : null;
$name = $input['name'] ?? '';
$cat_id = (int)($input['category_id'] ?? 0);
$base_price = (float)($input['price'] ?? 0);
$variations = $input['variations'] ?? []; 
$modifier_ids = $input['modifiers'] ?? []; 

if (!$name || $cat_id <= 0) { 
    echo json_encode(['success' => false, 'error' => "Missing Name or Category."]); 
    exit; 
}

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    // 1. UPDATE or INSERT Product
    if ($id) {
        $stmt = $mysqli->prepare("UPDATE products SET category_id=?, name=?, price=?, updated_at=NOW() WHERE id=?");
        if (!$stmt) throw new Exception("UPDATE Prep Failed");
        $stmt->bind_param('isdi', $cat_id, $name, $base_price, $id);
        if (!$stmt->execute()) throw new Exception("UPDATE Exec Failed");
        $product_id = $id;
    } else {
        $stmt = $mysqli->prepare("INSERT INTO products (category_id, name, price, available) VALUES (?, ?, ?, 1)");
        if (!$stmt) throw new Exception("INSERT Prep Failed");
        $stmt->bind_param('isd', $cat_id, $name, $base_price);
        if (!$stmt->execute()) throw new Exception("INSERT Exec Failed");
        $product_id = $mysqli->insert_id;
    }

    // 2. SMART VARIATIONS SYNC
    $current_vars = [];
    $get_vars = $mysqli->prepare("SELECT id FROM product_variations WHERE product_id = ?");
    $get_vars->bind_param('i', $product_id);
    $get_vars->execute();
    $v_res = $get_vars->get_result();
    while($v = $v_res->fetch_assoc()) $current_vars[] = (int)$v['id'];
    $get_vars->close();

    $incoming_v_ids = [];
    foreach ($variations as $index => $v) {
        $v_name = $v['name']; $v_price = (float)$v['price']; $v_id = !empty($v['id']) ? (int)$v['id'] : null; $sort = $index + 1;

        if ($v_id && in_array($v_id, $current_vars)) {
            $v_stmt = $mysqli->prepare("UPDATE product_variations SET name=?, price=?, sort_order=? WHERE id=?");
            $v_stmt->bind_param('sdii', $v_name, $v_price, $sort, $v_id);
            $v_stmt->execute();
            $incoming_v_ids[] = $v_id;
        } else {
            $v_stmt = $mysqli->prepare("INSERT INTO product_variations (product_id, name, price, sort_order) VALUES (?, ?, ?, ?)");
            $v_stmt->bind_param('isdi', $product_id, $v_name, $v_price, $sort);
            $v_stmt->execute();
            $incoming_v_ids[] = $mysqli->insert_id;
        }
    }

    $to_delete = array_diff($current_vars, $incoming_v_ids);
    if (!empty($to_delete)) {
        $ids_string = implode(',', $to_delete);
        if (!$mysqli->query("DELETE FROM product_variations WHERE id IN ($ids_string) AND id NOT IN (SELECT DISTINCT variation_id FROM order_items WHERE variation_id IS NOT NULL)")) {
            throw new Exception("VAR DELETE Failed");
        }
    }

    // 3. REBUILD MODIFIERS
    $del_mod = $mysqli->prepare("DELETE FROM product_modifiers WHERE product_id = ?");
    $del_mod->bind_param('i', $product_id);
    $del_mod->execute();
    
    if (!empty($modifier_ids)) {
        $m_stmt = $mysqli->prepare("INSERT INTO product_modifiers (product_id, modifier_id) VALUES (?, ?)");
        foreach ($modifier_ids as $mod_id) {
            $m_id = (int)$mod_id;
            $m_stmt->bind_param('ii', $product_id, $m_id);
            $m_stmt->execute();
        }
    }

    $mysqli->commit();
    echo json_encode(['success' => true, 'product_id' => $product_id]);
} catch (Exception $e) {
    if(isset($mysqli)) $mysqli->rollback();
    echo json_encode(['success' => false, 'error' => "Transaction failed. Database changes reverted."]);
}