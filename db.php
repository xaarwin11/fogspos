<?php
// Turn off screen errors for production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

// Turn on background logging
ini_set('log_errors', 1);
// Saves a file called 'error.log' in the same folder as db.php
ini_set('error_log', __DIR__ . '/error.log');
// fogs-app/db.php

if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && substr($line, 0, 1) !== '#') {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// FIX: Renamed all these to start with $db_ so they never collide with your app!
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '290505Slol';
$db_name = $_ENV['DB_NAME'] ?? 'fogs';

function get_db_conn() {
    global $db_host, $db_user, $db_pass, $db_name;

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
        $mysqli->set_charset("utf8mb4");
        $mysqli->query("SET time_zone = '+08:00'");
        return $mysqli;

    } catch (mysqli_sql_exception $e) {
        // DIAGNOSTIC MODE: Expose the raw MySQL error to the phone
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'MYSQL REJECTED: ' . $e->getMessage()]);
        exit;
    }
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$http_host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if (strpos($path, '/api') !== false || strpos($path, '/pos') !== false) {
    $path = dirname($path);
}
$base_url = "$protocol://$http_host$path";
?>