<?php
// get_reviews.php
session_start();
header('Content-Type: application/json');

// Database connection
require_once 'db_connect.php';

$book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;

if ($book_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid book ID']);
    exit;
}

// Get reviews with user information
$query = "SELECT r.*, u.name, u.avatar_url 
          FROM reviews r 
          JOIN users u ON r.user_id = u.user_id 
          WHERE r.book_id = ? 
          ORDER BY r.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $book_id);
$stmt->execute();
$result = $stmt->get_result();

$reviews = [];
$total_rating = 0;
$rating_count = 0;

while ($row = $result->fetch_assoc()) {
    $reviews[] = [
        'review_id' => $row['review_id'],
        'user_id' => $row['user_id'],
        'user_name' => $row['name'],
        'avatar_url' => $row['avatar_url'] ?: 'https://i.pravatar.cc/150?img=' . rand(1, 70),
        'rating' => $row['rating'],
        'comment' => $row['comment'],
        'created_at' => date('F j, Y', strtotime($row['created_at']))
    ];
    $total_rating += $row['rating'];
    $rating_count++;
}

// Calculate average rating
$average_rating = $rating_count > 0 ? round($total_rating / $rating_count, 1) : 0;

// Check if current user has reviewed this book
$user_review = null;
if (isset($_SESSION['user_id'])) {
    $user_stmt = $conn->prepare("SELECT * FROM reviews WHERE user_id = ? AND book_id = ?");
    $user_stmt->bind_param("ii", $_SESSION['user_id'], $book_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    if ($user_result->num_rows > 0) {
        $user_review = $user_result->fetch_assoc();
    }
    $user_stmt->close();
}

echo json_encode([
    'success' => true,
    'reviews' => $reviews,
    'average_rating' => $average_rating,
    'total_reviews' => $rating_count,
    'user_review' => $user_review
]);

$stmt->close();
$conn->close();
?>