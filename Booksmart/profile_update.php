<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

require_once __DIR__ . '/db_connect.php';

$uid = (int)$_SESSION['user_id'];

// Handle bio update
if (isset($_POST['bio'])) {
    $bio = trim($_POST['bio']);
    $stmt = $conn->prepare('UPDATE users SET bio = ? WHERE user_id = ?');
    $stmt->bind_param('si', $bio, $uid);
    $stmt->execute();
    $stmt->close();
}

// Handle avatar upload
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['avatar'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed)) {
        die('Invalid file type');
    }

    // ensure uploads dir exists
    $destDir = __DIR__ . '/uploads/avatars';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $uid . '_' . time() . '.' . $ext;
    $dest = $destDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Save relative URL to DB
        $relative = 'uploads/avatars/' . $filename;
        $stmt = $conn->prepare('UPDATE users SET avatar_url = ? WHERE user_id = ?');
        $stmt->bind_param('si', $relative, $uid);
        $stmt->execute();
        $stmt->close();
    } else {
        die('Failed to move uploaded file');
    }
}

// After processing, redirect back to home.html
header('Location: home.html');
exit;

?>
