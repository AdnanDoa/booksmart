<?php
// login.php
session_start();
ini_set('display_errors',1);
error_reporting(E_ALL);

// FIX: Catch the returned connection
$conn = require 'db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.html');
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    die('Please fill both fields.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die('Invalid email.');
}

// Fetch user (including role and subscription type)
$stmt = $conn->prepare('SELECT user_id, name, password_hash, role, subscription_type FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    die('No user found with that email.');
}

$stmt->bind_result($user_id, $name, $hash, $role, $subscription_type);
$stmt->fetch();

// Verify password
if (!password_verify($password, $hash)) {
    die('Wrong password.');
}

// Password OK — create session
$_SESSION['user_id'] = $user_id;
$_SESSION['user_name'] = $name;
$_SESSION['role'] = $role;
$_SESSION['subscription_type'] = $subscription_type;

if (function_exists('session_regenerate_id')) {
    session_regenerate_id(true);
}

header('Location: home.php');
exit;
?>