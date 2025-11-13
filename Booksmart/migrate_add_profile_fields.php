<?php
require_once __DIR__ . '/db_connect.php';

$cols = [];
$res = $conn->query("SHOW COLUMNS FROM users LIKE 'avatar_url'");
if ($res->num_rows === 0) {
    $cols[] = "ADD COLUMN avatar_url VARCHAR(500) DEFAULT NULL";
}
$res = $conn->query("SHOW COLUMNS FROM users LIKE 'bio'");
if ($res->num_rows === 0) {
    $cols[] = "ADD COLUMN bio TEXT DEFAULT NULL";
}

if (empty($cols)) {
    echo "No migration necessary.\n";
    exit;
}

$sql = 'ALTER TABLE users ' . implode(', ', $cols);
if ($conn->query($sql) === TRUE) {
    echo "Migration applied: " . $sql . "\n";
} else {
    echo "Migration failed: " . $conn->error . "\n";
}

?>
