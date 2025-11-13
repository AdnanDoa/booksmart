<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/db_connect.php';
$uid = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT user_id, name, email, role, subscription_type, avatar_url, bio FROM users WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// Ensure avatar_url is absolute or fallback
if (empty($row['avatar_url'])) {
    $row['avatar_url'] = 'https://i.pravatar.cc/150?img=32';
}

echo json_encode(['user' => $row]);

?>
