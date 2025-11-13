<?php
// register.php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);

// FIX: Catch the returned connection from db_connect.php
$conn = require_once __DIR__ . '/db_connect.php';

// sanity check: ensure $conn is available and is a mysqli instance
if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not available. Check db_connect.php and database credentials.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.html');
    exit;
}

// 1) Get and sanitize input
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

// 2) Basic validation
if ($name === '' || $email === '' || $password === '' || $confirm === '') {
    die('Please fill all fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die('Invalid email address.');
}
if ($password !== $confirm) {
    die('Passwords do not match.');
}
if (strlen($password) < 6) {
    die('Password must be at least 6 characters.');
}

// 3) Check if email already exists (prepared statement)
$stmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    die('Email already registered. Try logging in.');
}
$stmt->close();

// 4) Hash the password (do NOT store plain passwords)
$hash = password_hash($password, PASSWORD_DEFAULT);

// 5) Insert new user (include default role and subscription_type)
$role = 'user';
$subscription_type = 'free';

$insert = $conn->prepare('INSERT INTO users (name, email, password_hash, role, subscription_type) VALUES (?, ?, ?, ?, ?)');
if (!$insert) {
    die('Prepare failed: ' . $conn->error);
}
$insert->bind_param('sssss', $name, $email, $hash, $role, $subscription_type);
if ($insert->execute()) {
    // Registered successfully — redirect to login page with a success flag
    $insert->close();
    header('Location: login.html?registered=1');
    exit;
} else {
    die('Database error: ' . $conn->error);
}
?>