<?php
// config.php
session_start();

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'audiobook_platform');
define('DB_USER', 'root');
define('DB_PASS', '');

// API configuration
define('GUTENDEX_API', 'https://gutendex.com/books/');

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $pdo = null;
}

// Check if user is logged in (for protected pages)
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        if (isset($_GET['debug']) && $_GET['debug'] === '1') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Auth debug\n";
            echo "=================\n";
            echo "Session status: " . session_status() . "\n";
            echo "Session ID: " . session_id() . "\n";
            echo "\$_SESSION dump:\n";
            var_export($_SESSION);
            echo "\n\n\$_COOKIE dump:\n";
            var_export($_COOKIE);
            exit;
        }
        header('Location: login.html');
        exit;
    }
    return true;
}
?>