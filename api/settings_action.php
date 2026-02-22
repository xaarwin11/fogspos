<?php
require_once '../db.php';
session_start();
header('Content-Type: application/json');

// Security Fix: Hide errors in production
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    echo json_encode(['success' => false, 'error' => 'Unauthorized Access']); exit; 
}

$method = $_SERVER['REQUEST_METHOD'];

// Security Fix: Enforce CSRF token on all POST requests
if ($method === 'POST') {
    $headers = getallheaders();
    $csrf_token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Security token invalid. Please refresh the page.']);
        exit;
    }
}

$mysqli = get_db_conn();

function safeQuery($mysqli, $sql) {
    $res = $mysqli->query($sql);
    if (!$res) throw new Exception("DB ERROR"); // Silenced exact error output for production
    return $res->fetch_all(MYSQLI_ASSOC);
}

try {
    if (($_GET['action'] ?? '') === 'get_all') {
            
            $categories = safeQuery($mysqli, "SELECT * FROM categories ORDER BY id ASC");
            foreach($categories as &$c) {
                $cid = $c['id'];
                $mods = safeQuery($mysqli, "SELECT modifier_id FROM category_modifiers WHERE category_id = $cid");
                $c['modifiers'] = array_column($mods, 'modifier_id');
            }

            echo json_encode([
                'success' => true, 
                'categories' => $categories,
                'tables'     => safeQuery($mysqli, "SELECT t.id, t.table_number, IF(COUNT(o.id) > 0, 'occupied', 'open') as status FROM tables t LEFT JOIN orders o ON t.id = o.table_id AND o.status = 'open' GROUP BY t.id ORDER BY CAST(t.table_number AS UNSIGNED) ASC, t.table_number ASC"),
                'modifiers'  => safeQuery($mysqli, "SELECT * FROM modifiers ORDER BY name ASC"),
                'discounts'  => safeQuery($mysqli, "SELECT * FROM discounts ORDER BY name ASC"),
                'roles'      => safeQuery($mysqli, "SELECT * FROM roles"),
                'users'      => safeQuery($mysqli, "SELECT u.id, u.username, u.first_name, u.last_name, u.role_id, r.role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.is_active = 1 ORDER BY u.username ASC"),
                'printers'   => safeQuery($mysqli, "SELECT * FROM printers WHERE is_active = 1 ORDER BY name ASC"),
                'settings'   => safeQuery($mysqli, "SELECT * FROM system_settings"),
                // NEW: Fetching Logs & Timesheets
                'timesheets' => safeQuery($mysqli, "SELECT t.*, u.username FROM time_tracking t JOIN users u ON t.user_id = u.id ORDER BY t.clock_in DESC LIMIT 100"),
                'audit_logs' => safeQuery($mysqli, "SELECT a.*, u.username FROM audit_log a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 100")
            ]);
            exit;
    }
    
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        // --- 100% PREPARED STATEMENTS APPLIED BELOW ---
        if ($action === 'save_category') {
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $name = $input['name']; $type = $input['cat_type']; $mods = $input['modifiers'] ?? [];
            
            if ($id) { 
                $stmt = $mysqli->prepare("UPDATE categories SET name = ?, cat_type = ? WHERE id = ?");
                $stmt->bind_param('ssi', $name, $type, $id); $stmt->execute();
            } else { 
                $stmt = $mysqli->prepare("INSERT INTO categories (name, cat_type) VALUES (?, ?)");
                $stmt->bind_param('ss', $name, $type); $stmt->execute(); $id = $mysqli->insert_id; 
            }
            
            $d_stmt = $mysqli->prepare("DELETE FROM category_modifiers WHERE category_id = ?");
            $d_stmt->bind_param('i', $id); $d_stmt->execute();
            $m_stmt = $mysqli->prepare("INSERT INTO category_modifiers (category_id, modifier_id) VALUES (?, ?)");
            foreach($mods as $m) { $m_stmt->bind_param('ii', $id, $m); $m_stmt->execute(); }
            
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'delete_category') { 
            $id = (int)$input['id'];
            $c_stmt = $mysqli->prepare("SELECT id FROM products WHERE category_id = ? LIMIT 1");
            $c_stmt->bind_param('i', $id); $c_stmt->execute();
            if ($c_stmt->get_result()->num_rows > 0) throw new Exception("Cannot delete category. Products are assigned to it.");
            $d_stmt = $mysqli->prepare("DELETE FROM categories WHERE id = ?");
            $d_stmt->bind_param('i', $id); $d_stmt->execute();
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'save_table') {
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $tNum = $input['table_number'];
            if ($id) {
                $stmt = $mysqli->prepare("UPDATE tables SET table_number = ? WHERE id = ?");
                $stmt->bind_param('si', $tNum, $id); $stmt->execute();
            } else { 
                $stmt = $mysqli->prepare("INSERT INTO tables (table_number) VALUES (?)");
                $stmt->bind_param('s', $tNum); $stmt->execute();
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'delete_table') { 
            $id = (int)$input['id'];
            $c_stmt = $mysqli->prepare("SELECT id FROM orders WHERE table_id = ? AND status = 'open' LIMIT 1");
            $c_stmt->bind_param('i', $id); $c_stmt->execute();
            if ($c_stmt->get_result()->num_rows > 0) throw new Exception("Cannot delete table. It has an open order!");
            $d_stmt = $mysqli->prepare("DELETE FROM tables WHERE id = ?");
            $d_stmt->bind_param('i', $id); $d_stmt->execute();
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'save_modifier') { 
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $name = $input['name']; $price = (float)$input['price'];
            if ($id) {
                $stmt = $mysqli->prepare("UPDATE modifiers SET name = ?, price = ? WHERE id = ?");
                $stmt->bind_param('sdi', $name, $price, $id); $stmt->execute();
            } else { 
                $stmt = $mysqli->prepare("INSERT INTO modifiers (name, price, is_active) VALUES (?, ?, 1)");
                $stmt->bind_param('sd', $name, $price); $stmt->execute();
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'delete_modifier') { 
             $id = (int)$input['id']; 
             $stmt = $mysqli->prepare("DELETE FROM modifiers WHERE id = ?");
             $stmt->bind_param('i', $id); $stmt->execute();
             echo json_encode(['success' => true]); exit;
        }

        if ($action === 'save_discount') { 
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $name = $input['name']; $type = $input['type']; $val = (float)$input['value']; $target = $input['target_type'];
            if ($id) {
                $stmt = $mysqli->prepare("UPDATE discounts SET name=?, type=?, value=?, target_type=? WHERE id=?");
                $stmt->bind_param('ssdsi', $name, $type, $val, $target, $id); $stmt->execute();
            } else { 
                $stmt = $mysqli->prepare("INSERT INTO discounts (name, type, value, target_type, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->bind_param('ssds', $name, $type, $val, $target); $stmt->execute();
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'delete_discount') { 
            $id = (int)$input['id']; 
            $stmt = $mysqli->prepare("DELETE FROM discounts WHERE id = ?");
            $stmt->bind_param('i', $id); $stmt->execute();
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'save_staff') {
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $username = $input['username']; $first_name = $input['first_name']; $last_name = $input['last_name'];
            $role_id = (int)$input['role_id']; $pin = $input['pin'] ?? '';
            
            if ($id) {
                if (!empty($pin)) {
                    $hash = password_hash($pin, PASSWORD_DEFAULT);
                    $stmt = $mysqli->prepare("UPDATE users SET username=?, first_name=?, last_name=?, role_id=?, passcode=? WHERE id=?");
                    $stmt->bind_param('sssisi', $username, $first_name, $last_name, $role_id, $hash, $id);
                } else {
                    $stmt = $mysqli->prepare("UPDATE users SET username=?, first_name=?, last_name=?, role_id=? WHERE id=?");
                    $stmt->bind_param('sssii', $username, $first_name, $last_name, $role_id, $id);
                }
                $stmt->execute();
            } else {
                if (empty($pin)) throw new Exception("New staff must have a PIN.");
                $hash = password_hash($pin, PASSWORD_DEFAULT);
                $stmt = $mysqli->prepare("INSERT INTO users (username, first_name, last_name, role_id, passcode, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                $stmt->bind_param('sssis', $username, $first_name, $last_name, $role_id, $hash);
                $stmt->execute();
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'delete_staff') { 
            $id = (int)$input['id']; if ($id == $_SESSION['user_id']) throw new Exception("You cannot delete your own account.");
            $stmt = $mysqli->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
            $stmt->bind_param('i', $id); $stmt->execute();
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'save_printer') { 
            $id = !empty($input['id']) ? (int)$input['id'] : null;
            $name = $input['name']; $type = $input['connection_type']; $path = $input['path'];
            $beep = isset($input['beep']) ? (int)$input['beep'] : 1;
            $cut = isset($input['cut']) ? (int)$input['cut'] : 1;
            $char_limit = isset($input['character_limit']) ? (int)$input['character_limit'] : 32;

            if ($id) {
                $stmt = $mysqli->prepare("UPDATE printers SET name=?, connection_type=?, path=?, character_limit=?, beep_on_print=?, cut_after_print=? WHERE id=?");
                $stmt->bind_param('sssiiii', $name, $type, $path, $char_limit, $beep, $cut, $id); $stmt->execute();
            } else {
                $stmt = $mysqli->prepare("INSERT INTO printers (name, connection_type, path, character_limit, beep_on_print, cut_after_print, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param('sssiii', $name, $type, $path, $char_limit, $beep, $cut); $stmt->execute();
            }
            echo json_encode(['success' => true]); exit;
        }

        if ($action === 'save_settings_batch') { 
            $settings = $input['settings'] ?? [];
            $stmt = $mysqli->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            foreach ($settings as $key => $val) { $stmt->bind_param('ss', $val, $key); $stmt->execute(); }
            echo json_encode(['success' => true]); exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
} catch (Exception $e) { 
    echo json_encode(['success' => false, 'error' => 'A system error occurred. Check server logs.']); // Hides detailed SQL errors
}