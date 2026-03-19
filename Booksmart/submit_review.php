<?php
// submit_review.php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to submit a review']);
    exit;
}

// Database connection
require_once 'db_connect.php';

// Get POST data
$user_id = $_SESSION['user_id'];
$book_id = isset($_POST['book_id']) ? intval($_POST['book_id']) : 0;
$rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

// Validate inputs
if ($book_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
    exit;
}

if ($rating < 1 || $rating > 5) {
    echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
    exit;
}

if (empty($comment)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a review comment']);
    exit;
}

// Check if user has already reviewed this book
$check_stmt = $conn->prepare("SELECT review_id FROM reviews WHERE user_id = ? AND book_id = ?");
$check_stmt->bind_param("ii", $user_id, $book_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    // Update existing review
    $review = $check_result->fetch_assoc();
    $update_stmt = $conn->prepare("UPDATE reviews SET rating = ?, comment = ?, created_at = CURRENT_TIMESTAMP WHERE review_id = ?");
    $update_stmt->bind_param("isi", $rating, $comment, $review['review_id']);
    
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Review updated successfully', 'action' => 'updated']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update review']);
    }
    $update_stmt->close();
} else {
    // Insert new review
    $insert_stmt = $conn->prepare("INSERT INTO reviews (user_id, book_id, rating, comment) VALUES (?, ?, ?, ?)");
    $insert_stmt->bind_param("iiis", $user_id, $book_id, $rating, $comment);
    
    if ($insert_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Review submitted successfully', 'action' => 'inserted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to submit review']);
    }
    $insert_stmt->close();
}

$check_stmt->close();
$conn->close();
?>