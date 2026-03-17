<?php
// add_to_library.php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Check if book_id is provided
if (!isset($_POST['book_id']) || !is_numeric($_POST['book_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
    exit;
}

$user_id = $_SESSION['user_id'];
$book_id = (int)$_POST['book_id'];
$status = isset($_POST['status']) ? $_POST['status'] : 'want_to_read';

// Validate status
$allowed_statuses = ['reading', 'completed', 'want_to_read', 'purchased'];
if (!in_array($status, $allowed_statuses)) {
    $status = 'want_to_read'; // Default to want_to_read if invalid
}

// Database connection
require_once 'db_connect.php';

// Check if book exists
$check_book = $conn->prepare("SELECT book_id FROM books WHERE book_id = ?");
$check_book->bind_param("i", $book_id);
$check_book->execute();
$book_result = $check_book->get_result();

if ($book_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Book not found']);
    $check_book->close();
    $conn->close();
    exit;
}
$check_book->close();

// Check if already in user's library
$check_library = $conn->prepare("SELECT user_id, book_id FROM user_library WHERE user_id = ? AND book_id = ?");
$check_library->bind_param("ii", $user_id, $book_id);
$check_library->execute();
$library_result = $check_library->get_result();

if ($library_result->num_rows > 0) {
    // Book already in library - update status instead
    $update = $conn->prepare("UPDATE user_library SET status = ?, last_opened = CURRENT_TIMESTAMP WHERE user_id = ? AND book_id = ?");
    $update->bind_param("sii", $status, $user_id, $book_id);
    
    if ($update->execute()) {
        echo json_encode(['success' => true, 'message' => 'Book status updated', 'action' => 'updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update book status']);
    }
    $update->close();
} else {
    // Add new book to library
    $insert = $conn->prepare("INSERT INTO user_library (user_id, book_id, status, progress, last_opened) VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)");
    $insert->bind_param("iis", $user_id, $book_id, $status);
    
    if ($insert->execute()) {
        echo json_encode(['success' => true, 'message' => 'Book added to your library', 'action' => 'added']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add book to library']);
    }
    $insert->close();
}

$check_library->close();
$conn->close();
?>