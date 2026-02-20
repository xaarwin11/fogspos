<?php
require_once 'db.php';
$conn = get_db_conn();
$pass = password_hash("123456", PASSWORD_BCRYPT);
$conn->query("INSERT INTO users (username, passcode, role_id) VALUES ('Admin', '$pass', 1)");
echo "Admin Created. PIN: 1234";
?>