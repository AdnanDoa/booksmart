<?php
// update_status.php
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in first']);
    exit;
}

// Check if book_id and status are provided
if (!isset($_POST['book_id']) || !is_numeric($_POST['book_id']) || !isset($_POST['status'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_POST['book_id'];
$status = $_POST['status'];

// Validate status
$allowed_statuses = ['reading', 'completed', 'want_to_read', 'purchased'];
if (!in_array($status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

// Database connection
require_once 'db_connect.php';

// Check if connection is successful
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if book exists in user's library
$check = $conn->prepare("SELECT * FROM user_library WHERE user_id = ? AND book_id = ?");
$check->bind_param("ii", $user_id, $book_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Book not found in your library']);
    $check->close();
    $conn->close();
    exit;
}
$check->close();

// Update the status
$update = $conn->prepare("UPDATE user_library SET status = ?, last_opened = NOW() WHERE user_id = ? AND book_id = ?");
$update->bind_param("sii", $status, $user_id, $book_id);

if ($update->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Status updated successfully',
        'status' => $status
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $update->error]);
}

$update->close();
$conn->close();
?>