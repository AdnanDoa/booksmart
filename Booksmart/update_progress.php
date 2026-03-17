<?php
// update_progress.php
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

// Check if book_id and progress are provided
if (!isset($_POST['book_id']) || !is_numeric($_POST['book_id']) || !isset($_POST['progress'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_POST['book_id'];
$progress = (int)$_POST['progress'];

// Validate progress (0-100)
if ($progress < 0 || $progress > 100) {
    echo json_encode(['success' => false, 'message' => 'Progress must be between 0 and 100']);
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

// Update the progress
$update = $conn->prepare("UPDATE user_library SET progress = ?, last_opened = NOW() WHERE user_id = ? AND book_id = ?");
$update->bind_param("iii", $progress, $user_id, $book_id);

if ($update->execute()) {
    // If progress is 100%, optionally auto-update status to completed
    if ($progress == 100) {
        $status_update = $conn->prepare("UPDATE user_library SET status = 'completed' WHERE user_id = ? AND book_id = ? AND status != 'completed'");
        $status_update->bind_param("ii", $user_id, $book_id);
        $status_update->execute();
        $status_update->close();
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Progress updated successfully',
        'progress' => $progress
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update progress: ' . $update->error]);
}

$update->close();
$conn->close();
?>