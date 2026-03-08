<?php
// fogs-app/api/auth_logout.php
session_start();

// --- NEW: LOG THE LOGOUT TO AUDIT LOG BEFORE DESTROYING SESSION ---
//if (!empty($_SESSION['user_id'])) {
//   require_once '../db.php';
//    try {
//        $mysqli = get_db_conn();
//        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
//        $log_stmt = $mysqli->prepare("INSERT INTO audit_log (user_id, action_type, ip_address, created_at) VALUES (?, 'logout', ?, NOW())");
//        $log_stmt->bind_param('is', $_SESSION['user_id'], $ip);
//        $log_stmt->execute();
//        $log_stmt->close();
//    } catch (Exception $e) { /* Ignore DB errors on logout */ }
//}
// ------------------------------------------------------------------

// 1. Unset all session variables
$_SESSION = [];

// 2. Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Destroy the session
session_destroy();

// 4. Redirect to Login
header("Location: ../");
exit;
?>