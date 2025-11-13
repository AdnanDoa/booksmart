<?php

ini_set('display_errors', 1); 
error_reporting(E_ALL);

$host = 'localhost';
$user = 'root';
$pass = '';         
$db   = 'audiobook_platform';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_errno) {
   
    die("Database connection failed: (" . $conn->connect_errno . ") " . $conn->connect_error);
}

// ensure proper charset
$conn->set_charset("utf8mb4");
// Make absolutely sure the connection is available to any including script
$GLOBALS['conn'] = $conn;

// Also return the connection when this file is included with an assignment
return $conn;
?>
