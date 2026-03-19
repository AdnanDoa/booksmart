<?php
// delete_review.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in']);
    exit;
}

require_once 'db_connect.php';

$review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
$user_id = $_SESSION['user_id'];

if ($review_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid review ID']);
    exit;
}

// Verify the review belongs to the user
$stmt = $conn->prepare("DELETE FROM reviews WHERE review_id = ? AND user_id = ?");
$stmt->bind_param("ii", $review_id, $user_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true, 'message' => 'Review deleted successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Review not found or you do not have permission to delete it']);
}

$stmt->close();
$conn->close();
?>