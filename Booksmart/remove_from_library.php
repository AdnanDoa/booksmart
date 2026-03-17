<?php
// remove_from_library.php
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

// Check if book_id is provided
if (!isset($_POST['book_id']) || !is_numeric($_POST['book_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
    exit;
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_POST['book_id'];

// Database connection
require_once 'db_connect.php';

// Check if connection is successful
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// First, check if the book exists in user's library
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

// Delete the book from user_library
$delete = $conn->prepare("DELETE FROM user_library WHERE user_id = ? AND book_id = ?");
$delete->bind_param("ii", $user_id, $book_id);

if ($delete->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Book removed from your library successfully'
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to remove book: ' . $delete->error
    ]);
}

$delete->close();
$conn->close();
?>