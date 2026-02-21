<?php
require_once '../db.php';
session_start();

// Security: Only Admins and Managers can download the database
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'manager'])) { 
    die("Unauthorized Access"); 
}

try {
    $mysqli = get_db_conn();
    $tables = [];
    $result = $mysqli->query("SHOW TABLES");
    while ($row = $result->fetch_row()) { $tables[] = $row[0]; }

    $sql_dump = "-- FogsTasa POS Database Backup\n";
    $sql_dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($tables as $table) {
        $result = $mysqli->query("SELECT * FROM `$table`");
        $numFields = $result->field_count;
        
        $sql_dump .= "DROP TABLE IF EXISTS `$table`;\n";
        $row2 = $mysqli->query("SHOW CREATE TABLE `$table`")->fetch_row();
        $sql_dump .= $row2[1] . ";\n\n";
        
        while ($row = $result->fetch_row()) {
            $sql_dump .= "INSERT INTO `$table` VALUES(";
            for ($j = 0; $j < $numFields; $j++) {
                if (isset($row[$j])) { 
                    $escaped = $mysqli->real_escape_string($row[$j]);
                    $escaped = preg_replace("/\n/", "\\n", $escaped);
                    $sql_dump .= '"' . $escaped . '"'; 
                } else { 
                    $sql_dump .= 'NULL'; 
                }
                if ($j < ($numFields - 1)) { $sql_dump .= ','; }
            }
            $sql_dump .= ");\n";
        }
        $sql_dump .= "\n\n";
    }

    // Force Download the File
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="fogs_backup_' . date('Y_m_d_H_i') . '.sql"');
    header('Content-Length: ' . strlen($sql_dump));
    echo $sql_dump;
    exit;

} catch (Exception $e) {
    die("Backup Failed: " . $e->getMessage());
}
?>