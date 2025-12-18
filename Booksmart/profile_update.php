<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

require_once __DIR__ . '/db_connect.php';

$uid = (int)$_SESSION['user_id'];

// Detect AJAX requests (fetch from client sets this header)
$isAjax = false;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $isAjax = true;
}

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
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type']);
            exit;
        } else {
            http_response_code(400);
            echo 'Invalid file type';
            exit;
        }
    }

    // Additional image validation
    $imgInfo = @getimagesize($file['tmp_name']);
    if ($imgInfo === false) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['error' => 'Uploaded file is not a valid image']);
            exit;
        } else {
            http_response_code(400);
            echo 'Uploaded file is not a valid image';
            exit;
        }
    }

    // ensure uploads dir exists
    $destDir = __DIR__ . '/uploads/avatars';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $uid . '_' . time() . '.' . $ext;
    $dest = $destDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        // Save relative URL to DB (no leading slash)
        $relative = 'uploads/avatars/' . $filename;
        $stmt = $conn->prepare('UPDATE users SET avatar_url = ? WHERE user_id = ?');
        $stmt->bind_param('si', $relative, $uid);
        if (!$stmt->execute()) {
            // DB update failed - remove uploaded file
            @unlink($dest);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update avatar in database']);
                exit;
            } else {
                http_response_code(500);
                echo 'Failed to update avatar in database';
                exit;
            }
        }
        $stmt->close();
    } else {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['error' => 'Failed to move uploaded file']);
            exit;
        } else {
            http_response_code(500);
            echo 'Failed to move uploaded file';
            exit;
        }
    }
}

// After processing, respond appropriately for AJAX or normal form
$isAjax = false;
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $isAjax = true;
}

// Prepare response: return absolute avatar URL for clients
$absoluteAvatar = null;
if (isset($relative) && $relative) {
    $absoluteAvatar = '' . ltrim($relative, '');
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'avatar_url' => $absoluteAvatar]);
    exit;
} else {
    // Normal form submission - redirect back to home
    header('Location: home.php');
    exit;
}

?>
