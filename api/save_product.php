<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id'])) { echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit; }

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
$name = trim($input['name'] ?? '');
$cat_id = (int)($input['category_id'] ?? 0);
$base_price = (float)($input['price'] ?? 0);
$available = isset($input['available']) ? (int)$input['available'] : 1; 
$variations = $input['variations'] ?? []; 
$modifier_ids = $input['modifiers'] ?? []; 

if (!$name || $cat_id <= 0) { 
    echo json_encode(['success' => false, 'error' => "Missing Name or Category."]); 
    exit; 
}

try {
    $mysqli = get_db_conn();
    $mysqli->begin_transaction();

    // ============================================================
    // FIX 1: STRICT DUPLICATE NAME CHECKER! (Blocks Double-clicks)
    // ============================================================
    $check_id = $id ? $id : 0;
    $dup_stmt = $mysqli->prepare("SELECT id FROM products WHERE name = ? AND id != ?");
    $dup_stmt->bind_param('si', $name, $check_id);
    $dup_stmt->execute();
    if ($dup_stmt->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'error' => 'A product with this exact name already exists!']);
        exit;
    }
    $dup_stmt->close();

    // 1. UPDATE or INSERT Product
    if ($id) {
        $stmt = $mysqli->prepare("UPDATE products SET category_id=?, name=?, price=?, available=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('isdii', $cat_id, $name, $base_price, $available, $id);
        $stmt->execute();
        $product_id = $id;
    } else {
        $stmt = $mysqli->prepare("INSERT INTO products (category_id, name, price, available) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isdi', $cat_id, $name, $base_price, $available);
        $stmt->execute();
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

    // ============================================================
    // THE FIX: UNLINK AND FORCE DELETE VARIATIONS
    // ============================================================
    $to_delete = array_diff($current_vars, $incoming_v_ids);
    if (!empty($to_delete)) {
        $ids_string = implode(',', $to_delete);
        
        // 1. Safely detach from old receipts so they don't break (the text name is already saved on them)
        $mysqli->query("UPDATE order_items SET variation_id = NULL WHERE variation_id IN ($ids_string)");
        
        // 2. Now cleanly delete the variations so they completely disappear!
        $mysqli->query("DELETE FROM product_variations WHERE id IN ($ids_string)");
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
    echo json_encode(['success' => false, 'error' => "Transaction failed."]);
}
?>