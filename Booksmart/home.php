<?php
session_start();

// Debug mode: visit home.php?debug=1 after attempting login to see session/cookie state
$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');

if (!isset($_SESSION['user_id'])) {
    if ($debug) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "home.php debug\n";
        echo "=================\n";
        echo "Session status: " . session_status() . "\n";
        echo "Session ID: " . session_id() . "\n";
        echo "\n";
        echo "\$_SESSION dump:\n";
        var_export($_SESSION);
        echo "\n\n\$_COOKIE dump:\n";
        var_export($_COOKIE);
        echo "\n\nHeaders sent: " . (headers_sent() ? 'yes' : 'no') . "\n";
        exit;
    }

    header('Location: login.html');
    exit;
}

// Database connection
require_once __DIR__ . '/db_connect.php';

// Fetch user data
$user = null;
if (isset($conn) && $conn) {
    if ($stmt = $conn->prepare("SELECT user_id, name, email, avatar_url, bio, created_at FROM users WHERE user_id = ?")) {
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

// Set user name for session if not set
if (!isset($_SESSION['user_name']) && $user) {
    $_SESSION['user_name'] = $user['name'];
}

// Fetch featured books (most popular or recently added)
$featured_books = [];
try {
    // Get books with average ratings and review counts
    $result = $conn->query("
        SELECT b.book_id, b.title, b.author, b.description, b.cover_url, bf.file_url,
               COALESCE(AVG(r.rating), 0) as avg_rating,
               COUNT(DISTINCT r.review_id) as review_count,
               COUNT(DISTINCT ul.user_id) as reader_count
        FROM books b
        LEFT JOIN book_files bf ON b.book_id = bf.book_id AND bf.file_type = 'pdf'
        LEFT JOIN reviews r ON b.book_id = r.book_id
        LEFT JOIN user_library ul ON b.book_id = ul.book_id
        WHERE bf.file_url IS NOT NULL
        GROUP BY b.book_id
        ORDER BY 
            CASE 
                WHEN COUNT(DISTINCT r.review_id) > 0 THEN COALESCE(AVG(r.rating), 0) * LOG(COUNT(DISTINCT r.review_id) + 1)
                ELSE 0
            END DESC,
            b.created_at DESC
        LIMIT 12
    ");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $featured_books[] = $row;
        }
        $result->close();
    }
    
    // If no books found, try to import some
    if (count($featured_books) === 0) {
        $apiUrl = "https://gutendex.com/books/?languages=en&mime_type=application/pdf&page=1";
        $json = @file_get_contents($apiUrl);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (isset($data['results'])) {
                foreach ($data['results'] as $book) {
                    $title = $conn->real_escape_string($book['title'] ?? 'Untitled');
                    $author = $conn->real_escape_string(!empty($book['authors']) ? $book['authors'][0]['name'] : 'Unknown Author');
                    $description = $conn->real_escape_string(!empty($book['subjects']) ? implode(', ', array_slice($book['subjects'], 0, 4)) : 'Classic literature');

                    // Find PDF URL
                    $pdfUrl = '';
                    if (!empty($book['formats']) && is_array($book['formats'])) {
                        foreach ($book['formats'] as $mime => $url) {
                            if (stripos($mime, 'pdf') !== false && $url) {
                                $pdfUrl = $conn->real_escape_string($url);
                                break;
                            }
                        }
                    }
                    if (empty($pdfUrl)) continue;

                    // Find cover image
                    $coverUrl = '';
                    if (!empty($book['formats']) && is_array($book['formats'])) {
                        foreach ($book['formats'] as $mime => $url) {
                            if (stripos($mime, 'image') !== false && $url) {
                                $coverUrl = $conn->real_escape_string($url);
                                break;
                            }
                        }
                    }
                    if (empty($coverUrl)) {
                        $coverUrl = $conn->real_escape_string("https://covers.openlibrary.org/b/title/" . rawurlencode($title) . "-L.jpg");
                    }

                    // Check if book already exists
                    $check = $conn->query("SELECT book_id FROM books WHERE title = '$title' AND author = '$author'");
                    if ($check && $check->num_rows === 0) {
                        $conn->query("INSERT INTO books (title, author, description, cover_url, file_type, is_public_domain, created_at) 
                                     VALUES ('$title', '$author', '$description', '$coverUrl', 'pdf', TRUE, NOW())");
                        $bookId = $conn->insert_id;
                        if ($bookId) {
                            $conn->query("INSERT INTO book_files (book_id, file_type, file_url) 
                                         VALUES ($bookId, 'pdf', '$pdfUrl')");
                        }
                    }
                    if ($check) $check->close();
                }
            }
        }

        // Re-run select after import
        $result = $conn->query("
            SELECT b.book_id, b.title, b.author, b.description, b.cover_url, bf.file_url,
                   COALESCE(AVG(r.rating), 0) as avg_rating,
                   COUNT(DISTINCT r.review_id) as review_count
            FROM books b
            LEFT JOIN book_files bf ON b.book_id = bf.book_id AND bf.file_type = 'pdf'
            LEFT JOIN reviews r ON b.book_id = r.book_id
            WHERE bf.file_url IS NOT NULL
            GROUP BY b.book_id
            ORDER BY b.book_id DESC
            LIMIT 12
        ");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $featured_books[] = $row;
            }
            $result->close();
        }
    }
} catch (Exception $e) {
    error_log('Home page fetch failed: ' . $e->getMessage());
    // Fallback: Try simple query
    $simple_result = $conn->query("SELECT b.book_id, b.title, b.author, b.description, b.cover_url, bf.file_url, 0 as avg_rating, 0 as review_count
                                   FROM books b 
                                   JOIN book_files bf ON b.book_id = bf.book_id 
                                   WHERE bf.file_type = 'pdf' 
                                   ORDER BY b.book_id DESC 
                                   LIMIT 12");
    if ($simple_result) {
        while ($row = $simple_result->fetch_assoc()) {
            $featured_books[] = $row;
        }
        $simple_result->close();
    }
}


// Fetch user's reading stats
$user_stats = [
    'total_read' => 0,
    'currently_reading' => 0,
    'total_reviews' => 0
];
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
        COUNT(CASE WHEN status = 'reading' THEN 1 END) as reading
    FROM user_library WHERE user_id = ?
");
$stats_stmt->bind_param("i", $_SESSION['user_id']);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
if ($stats_result && $stats_result->num_rows > 0) {
    $stats = $stats_result->fetch_assoc();
    $user_stats['total_read'] = $stats['completed'] ?? 0;
    $user_stats['currently_reading'] = $stats['reading'] ?? 0;
}
$stats_stmt->close();

$review_count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM reviews WHERE user_id = ?");
$review_count_stmt->bind_param("i", $_SESSION['user_id']);
$review_count_stmt->execute();
$review_count_result = $review_count_stmt->get_result();
if ($review_count_result && $review_count_result->num_rows > 0) {
    $user_stats['total_reviews'] = $review_count_result->fetch_assoc()['count'] ?? 0;
}
$review_count_stmt->close();

// Fetch recent reviews from community
$recent_reviews = [];
$reviews_query = $conn->query("
    SELECT r.*, u.name as user_name, u.avatar_url, b.title as book_title, b.cover_url as book_cover
    FROM reviews r
    JOIN users u ON r.user_id = u.user_id
    JOIN books b ON r.book_id = b.book_id
    ORDER BY r.created_at DESC
    LIMIT 6
");
if ($reviews_query) {
    while ($row = $reviews_query->fetch_assoc()) {
        $recent_reviews[] = $row;
    }
    $reviews_query->close();
}

// Function to escape output
function e($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}

// Function to format time ago
function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time);
    }
}

// Avatar URL
$avatar_url = (!empty($user['avatar_url'])) ? $user['avatar_url'] : 'https://i.pravatar.cc/150?img=32';
$user_name = $user['name'] ?? $_SESSION['user_name'] ?? 'Reader';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booksmart - Discover Your Next Favorite Book</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --primary-light: #4895ef;
            --secondary: #7209b7;
            --secondary-light: #b5179e;
            --accent: #f72585;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --success: #4cc9f0;
            --warning: #ff9e00;
            --border-radius: 12px;
            --border-radius-lg: 20px;
            --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            --box-shadow-lg: 0 20px 40px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf5 100%);
            color: var(--dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* Header Styles */
        header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 15px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .logo {
            display: flex;
            align-items: center;
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 28px;
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }

        .logo i {
            margin-right: 10px;
            font-size: 32px;
            color: var(--secondary);
        }

        .logo:hover {
            transform: translateY(-2px);
        }

        .nav-links {
            display: flex;
            gap: 30px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: var(--transition);
            position: relative;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary);
            transition: var(--transition);
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-links a.active {
            color: var(--primary);
        }

        .nav-links a.active::after {
            width: 100%;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .search-bar {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-bar input {
            padding: 12px 20px 12px 45px;
            border-radius: 30px;
            border: 1px solid var(--light-gray);
            background: var(--light);
            font-size: 1em;
            width: 300px;
            transition: var(--transition);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.15);
            width: 350px;
        }

        .search-bar i {
            position: absolute;
            left: 18px;
            color: var(--gray);
        }

        .profile {
            position: relative;
        }

        .profile img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
            border: 2px solid transparent;
        }

        .profile img:hover {
            transform: scale(1.1);
            border-color: var(--primary);
        }

        .profile-dropdown {
            position: absolute;
            top: 60px;
            right: 0;
            background: white;
            min-width: 220px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow-lg);
            padding: 15px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            z-index: 100;
        }

        .profile:hover .profile-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .profile-dropdown img {
            width: 70px;
            height: 70px;
            display: block;
            margin: 0 auto 15px;
            border-radius: 50%;
            object-fit: cover;
        }

        .profile-dropdown h3 {
            text-align: center;
            margin-bottom: 15px;
            font-size: 18px;
        }

        .profile-dropdown a {
            display: block;
            padding: 10px 15px;
            text-decoration: none;
            color: var(--dark);
            border-radius: 6px;
            transition: var(--transition);
        }

        .profile-dropdown a:hover {
            background: var(--light-gray);
            color: var(--primary);
            padding-left: 20px;
        }

        .profile-dropdown a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        /* Hero Section */
        .hero {
            padding: 80px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
            color: white;
            border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
            margin-bottom: 50px;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" opacity="0.05"><path fill="white" d="M500,100 C700,50 900,150 900,350 C900,550 700,650 500,600 C300,650 100,550 100,350 C100,150 300,50 500,100 Z"/></svg>');
            background-size: cover;
        }

        .hero-content {
            max-width: 600px;
            z-index: 1;
        }

        .hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: 3.5rem;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .hero p {
            font-size: 1.2rem;
            margin-bottom: 30px;
            opacity: 0.9;
        }

        .hero-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 14px 30px;
            border-radius: 30px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            cursor: pointer;
            border: none;
            font-size: 1rem;
        }

        .btn-primary {
            background: white;
            color: var(--primary);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background: transparent;
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.5);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-3px);
        }

        .hero-image {
            position: relative;
            z-index: 1;
        }

        .hero-image img {
            max-width: 500px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow-lg);
            transform: perspective(1000px) rotateY(-10deg);
            transition: var(--transition);
        }

        .hero-image:hover img {
            transform: perspective(1000px) rotateY(-5deg) translateY(-10px);
        }

        /* Stats Cards */
        .stats-section {
            padding: 0 5% 40px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px 20px;
            text-align: center;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .stat-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Reading Challenge Card */
        .challenge-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin: 0 5% 40px;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .challenge-info h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .challenge-info p {
            opacity: 0.9;
        }

        .challenge-progress {
            min-width: 250px;
        }

        .progress-text {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: white;
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .challenge-btn {
            background: rgba(255, 255, 255, 0.2);
            padding: 10px 25px;
            border-radius: 30px;
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .challenge-btn:hover {
            background: white;
            color: var(--primary);
        }

        /* Section Styles */
        .section {
            padding: 60px 5%;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            color: var(--dark);
            position: relative;
            display: inline-block;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--primary);
            border-radius: 2px;
        }

        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .view-all:hover {
            gap: 12px;
        }

        /* Books Grid */
        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 30px;
        }

        .book-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            position: relative;
            cursor: pointer;
        }

        .book-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--box-shadow-lg);
        }

        .book-cover {
            position: relative;
            overflow: hidden;
            height: 300px;
        }

        .book-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }

        .book-card:hover .book-cover img {
            transform: scale(1.05);
        }

        .book-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            opacity: 0;
            transition: var(--transition);
        }

        .book-card:hover .book-overlay {
            opacity: 1;
        }

        .book-action {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            border: none;
        }

        .book-action:hover {
            background: var(--primary-dark);
            transform: scale(1.1);
        }

        .book-info {
            padding: 20px;
        }

        .book-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 8px;
            color: var(--dark);
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .book-author {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 15px;
        }

        .book-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .book-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--warning);
        }

        .book-status {
            background: var(--light-gray);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-available {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
        }

        /* Reviews Grid */
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
        }

        .review-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
        }

        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .review-header {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .review-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
        }

        .review-user {
            flex: 1;
        }

        .review-user h4 {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .review-date {
            font-size: 0.8rem;
            color: var(--gray);
        }

        .review-rating {
            display: flex;
            gap: 3px;
            margin-bottom: 12px;
            color: var(--warning);
        }

        .review-text {
            color: var(--gray);
            margin-bottom: 15px;
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .review-book {
            display: flex;
            gap: 12px;
            align-items: center;
            padding-top: 12px;
            border-top: 1px solid var(--light-gray);
            text-decoration: none;
            color: var(--dark);
        }

        .review-book-cover {
            width: 40px;
            height: 55px;
            border-radius: 6px;
            object-fit: cover;
        }

        .review-book-title {
            font-weight: 500;
            font-size: 0.9rem;
        }

        /* Categories Section */
        .categories {
            background: var(--light);
            border-radius: var(--border-radius-lg);
            padding: 60px 5%;
            margin: 0 5% 40px;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
        }

        .category-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px 20px;
            text-align: center;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: var(--dark);
            display: block;
        }

        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .category-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--primary);
            font-size: 24px;
        }

        .category-card h3 {
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .category-card p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        /* Newsletter */
        .newsletter {
            background: white;
            padding: 60px 5%;
            border-radius: var(--border-radius-lg);
            text-align: center;
            margin: 0 5% 60px;
            box-shadow: var(--box-shadow);
        }

        .newsletter h2 {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            margin-bottom: 15px;
        }

        .newsletter p {
            max-width: 600px;
            margin: 0 auto 30px;
            color: var(--gray);
        }

        .newsletter-form {
            display: flex;
            max-width: 500px;
            margin: 0 auto;
            gap: 10px;
        }

        .newsletter-form input {
            flex: 1;
            padding: 15px 20px;
            border-radius: 30px;
            border: 1px solid var(--light-gray);
            font-size: 1rem;
            transition: var(--transition);
        }

        .newsletter-form input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.15);
        }

        .newsletter-form button {
            padding: 15px 30px;
            border-radius: 30px;
            background: var(--primary);
            color: white;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .newsletter-form button:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        /* Footer */
        footer {
            background: var(--dark);
            color: white;
            padding: 60px 5% 30px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 30px;
            height: 2px;
            background: var(--primary);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: var(--transition);
        }

        .footer-links a:hover {
            color: white;
            padding-left: 5px;
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .social-links a:hover {
            background: var(--primary);
            transform: translateY(-3px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.9rem;
        }

        /* Modal Styles */
        #book-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(10px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        #book-modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: var(--border-radius-lg);
            max-width: 900px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            transform: scale(0.9);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow: hidden;
        }

        #book-modal.active .modal-content {
            transform: scale(1);
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: none;
            font-size: 1.5em;
            color: var(--primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            transition: var(--transition);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .modal-close:hover {
            background: var(--primary);
            color: white;
            transform: rotate(90deg);
        }

        .modal-body {
            display: flex;
            flex-direction: column;
            padding: 0;
        }

        @media (min-width: 768px) {
            .modal-body {
                flex-direction: row;
            }
        }

        .modal-cover-section {
            flex: 1;
            padding: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            position: relative;
            overflow: hidden;
        }

        .cover-container {
            position: relative;
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
            z-index: 2;
        }

        #modal-cover {
            width: 100%;
            border-radius: var(--border-radius);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            transition: var(--transition);
        }

        .modal-details-section {
            flex: 1.5;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        #modal-title {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--dark);
        }

        #modal-author {
            font-size: 1.1rem;
            color: var(--gray);
            margin-bottom: 20px;
        }

        .modal-rating {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .stars {
            display: flex;
            gap: 5px;
            color: var(--warning);
        }

        #modal-status {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 20px;
            align-self: flex-start;
        }

        .modal-description {
            margin-bottom: 25px;
            line-height: 1.7;
            color: var(--gray);
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-btn {
            flex: 1;
            min-width: 120px;
            padding: 12px 20px;
            border-radius: 30px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            cursor: pointer;
            border: none;
            font-size: 0.95rem;
            gap: 8px;
        }

        .action-btn.primary {
            background: var(--primary);
            color: white;
        }

        .action-btn.secondary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }

        /* Responsive */
        @media (max-width: 1100px) {
            .hero {
                flex-direction: column;
                text-align: center;
            }
            
            .hero-content {
                margin-bottom: 40px;
            }
            
            .hero-buttons {
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }
            
            .search-bar input {
                width: 200px;
            }
            
            .search-bar input:focus {
                width: 250px;
            }
            
            .hero h1 {
                font-size: 2.5rem;
            }
            
            .section-title {
                font-size: 1.8rem;
            }
            
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 20px;
            }
            
            .reviews-grid {
                grid-template-columns: 1fr;
            }
            
            .challenge-card {
                flex-direction: column;
                text-align: center;
            }
            
            .newsletter-form {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .book-cover {
                height: 240px;
            }
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <a href="home.php" class="logo">
            <i class="fas fa-book-open"></i>
            Booksmart
        </a>
        
        <nav class="nav-links">
            <a href="home.php" class="active">Home</a>
            <a href="catalog.php">Catalog</a>
            <a href="mybooks.php">My Books</a>
            <a href="reviews.php">Reviews</a>
            <a href="challenge.php">Challenge</a>
        </nav>
        
        <div class="header-actions">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search books, authors..." id="search-input">
            </div>
            
            <div class="profile">
                <img id="headerAvatar" src="<?php echo e($avatar_url); ?>" alt="Profile">
                <div class="profile-dropdown">
                    <img src="<?php echo e($avatar_url); ?>" alt="Profile">
                    <h3><?php echo e($user_name); ?></h3>
                    <a href="profpage.php"><i class="fas fa-user"></i> My Profile</a>
                    <a href="mybooks.php"><i class="fas fa-bookmark"></i> My Library</a>
                    <a href="#"><i class="fas fa-cog"></i> Settings</a>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <h1>Discover Your Next Favorite Book</h1>
            <p>Explore thousands of books, track your reading, and connect with fellow book lovers in our vibrant community.</p>
            <div class="hero-buttons">
                <a href="catalog.php" class="btn btn-primary">Explore Catalog</a>
                <a href="challenge.php" class="btn btn-secondary">Join Challenge</a>
            </div>
        </div>
        <div class="hero-image">
            <img src="https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c?ixlib=rb-4.0.3&auto=format&fit=crop&w=1000&q=80" alt="Books Collection">
        </div>
    </section>

    <!-- Stats Section -->
    <div class="stats-section">
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-book-reader"></i></div>
                <div class="stat-number"><?php echo $user_stats['total_read']; ?></div>
                <div class="stat-label">Books Read</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-book-open"></i></div>
                <div class="stat-number"><?php echo $user_stats['currently_reading']; ?></div>
                <div class="stat-label">Currently Reading</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-star"></i></div>
                <div class="stat-number"><?php echo $user_stats['total_reviews']; ?></div>
                <div class="stat-label">Reviews Written</div>
            </div>
           
        </div>
    </div>


    <!-- Featured Books Section -->
    <section class="section">
        <div class="section-header">
            <h2 class="section-title">Featured Books</h2>
            <a href="catalog.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <div class="books-grid" id="featured-books">
            <?php if (!empty($featured_books)): ?>
                <?php foreach ($featured_books as $book): ?>
                    <div class='book-card' data-book-id='<?php echo e($book['book_id'] ?? ''); ?>'>
                        <div class='book-cover'>
                            <img src='<?php echo e($book['cover_url'] ?? 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>' alt='<?php echo e($book['title'] ?? ''); ?>' onerror="this.src='https://images.unsplash.com/photo-1544947950-fa07a98d237f?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80'">
                            <div class='book-overlay'>
                                <button class='book-action view-details'><i class='fas fa-eye'></i></button>
                                <button class='book-action add-to-library'><i class='fas fa-bookmark'></i></button>
                                <button class='book-action share-book'><i class='fas fa-share-alt'></i></button>
                            </div>
                        </div>
                        <div class='book-info'>
                            <h3 class='book-title'><?php echo e($book['title'] ?? 'Untitled'); ?></h3>
                            <p class='book-author'><?php echo e($book['author'] ?? 'Unknown Author'); ?></p>
                            <div class='book-meta'>
                                <div class='book-rating'>
                                    <i class='fas fa-star'></i>
                                    <span><?php echo number_format($book['avg_rating'] ?? 0, 1); ?></span>
                                    <?php if (($book['review_count'] ?? 0) > 0): ?>
                                        <span style="font-size: 0.7rem;">(<?php echo $book['review_count']; ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <span class='book-status status-available'>Available</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style='grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--gray);'>No books found in the database. <a href="catalog.php">Browse catalog</a> to add books.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Recent Reviews Section -->
    <?php if (!empty($recent_reviews)): ?>
    <section class="section" style="padding-top: 0;">
        <div class="section-header">
            <h2 class="section-title">Recent Community Reviews</h2>
            <a href="reviews.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <div class="reviews-grid">
            <?php foreach ($recent_reviews as $review): ?>
                <div class="review-card">
                    <div class="review-header">
                        <img src="<?php echo e($review['avatar_url'] ?? 'https://i.pravatar.cc/150?img=' . rand(1, 70)); ?>" alt="<?php echo e($review['user_name']); ?>" class="review-avatar">
                        <div class="review-user">
                            <h4><?php echo e($review['user_name']); ?></h4>
                            <div class="review-date"><?php echo time_ago($review['created_at']); ?></div>
                        </div>
                    </div>
                    <div class="review-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php if ($i <= $review['rating']): ?>
                                <i class="fas fa-star"></i>
                            <?php else: ?>
                                <i class="far fa-star"></i>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                    <div class="review-text">
                        <?php echo e(strlen($review['comment']) > 150 ? substr($review['comment'], 0, 150) . '...' : $review['comment']); ?>
                    </div>
                    <a href="reviews.php?book_id=<?php echo $review['book_id']; ?>" class="review-book">
                        <img src="<?php echo e($review['book_cover'] ?? 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($review['book_title']); ?>" class="review-book-cover">
                        <span class="review-book-title"><?php echo e($review['book_title']); ?></span>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- Categories Section -->
    <div class="categories">
        <div class="section-header">
            <h2 class="section-title">Browse Categories</h2>
            <a href="catalog.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
        </div>
        
        <div class="categories-grid">
            <?php
            $categories = [
                ['icon' => 'fa-magic', 'name' => 'Fantasy', 'count' => 0],
                ['icon' => 'fa-user-secret', 'name' => 'Mystery', 'count' => 0],
                ['icon' => 'fa-heart', 'name' => 'Romance', 'count' => 0],
                ['icon' => 'fa-rocket', 'name' => 'Sci-Fi', 'count' => 0],
                ['icon' => 'fa-graduation-cap', 'name' => 'Non-Fiction', 'count' => 0],
                ['icon' => 'fa-theater-masks', 'name' => 'Drama', 'count' => 0],
            ];
            foreach ($categories as $cat):
            ?>
                <a href="catalog.php?category=<?php echo strtolower($cat['name']); ?>" class="category-card">
                    <div class="category-icon">
                        <i class="fas <?php echo $cat['icon']; ?>"></i>
                    </div>
                    <h3><?php echo $cat['name']; ?></h3>
                    <p><?php echo $cat['count']; ?> books</p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Newsletter -->
    <div class="newsletter">
        <h2>Stay Updated</h2>
        <p>Subscribe to our newsletter to receive the latest book recommendations, news, and exclusive offers.</p>
        <form class="newsletter-form" id="newsletter-form">
            <input type="email" placeholder="Your email address" required>
            <button type="submit">Subscribe</button>
        </form>
    </div>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="footer-column">
                <h3>Booksmart</h3>
                <p>Your personal library in the cloud. Discover, track, and share your reading journey with fellow book lovers.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-pinterest"></i></a>
                </div>
            </div>
            
            <div class="footer-column">
                <h3>Explore</h3>
                <ul class="footer-links">
                    <li><a href="home.php">Home</a></li>
                    <li><a href="catalog.php">Catalog</a></li>
                    <li><a href="mybooks.php">My Books</a></li>
                    <li><a href="reviews.php">Reviews</a></li>
                    <li><a href="challenge.php">Reading Challenge</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Account</h3>
                <ul class="footer-links">
                    <li><a href="profpage.php">Profile</a></li>
                    <li><a href="mybooks.php">My Library</a></li>
                    <li><a href="#">Settings</a></li>
                    <li><a href="#">Help & Support</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Contact</h3>
                <ul class="footer-links">
                    <li><i class="fas fa-map-marker-alt"></i> 123 Book Street, City</li>
                    <li><i class="fas fa-phone"></i> +1 234 567 890</li>
                    <li><i class="fas fa-envelope"></i> info@booksmart.com</li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Booksmart. All rights reserved.</p>
        </div>
    </footer>

    <!-- Book Modal -->
    <div id="book-modal">
        <div class="modal-content">
            <button class="modal-close" id="close-modal">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-body">
                <div class="modal-cover-section">
                    <div class="cover-container">
                        <img id="modal-cover" src="" alt="Book Cover">
                    </div>
                </div>
                <div class="modal-details-section">
                    <h2 id="modal-title">Book Title</h2>
                    <p id="modal-author">by Author Name</p>
                    
                    <div class="modal-rating">
                        <div class="stars" id="modal-stars"></div>
                        <span id="modal-rating-value">0.0</span>
                    </div>
                    
                    <span id="modal-status" class="status-available">Available</span>
                    
                    <p class="modal-description" id="modal-description">
                        Book description will appear here...
                    </p>
                    
                    <div class="modal-actions">
                        <button class="action-btn primary" id="read-pdf">
                            <i class="fas fa-book-open"></i> Read Now
                        </button>
                        <button class="action-btn secondary" id="add-to-library-modal">
                            <i class="fas fa-bookmark"></i> Add to Library
                        </button>
                        <button class="action-btn secondary" id="view-reviews-modal">
                            <i class="fas fa-star"></i> Reviews
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Book data from PHP
        const booksData = <?php
            $__books_map = [];
            foreach ($featured_books as $__b) {
                if (empty($__b['book_id'])) continue;
                $__books_map[(string)$__b['book_id']] = [
                    'title' => $__b['title'] ?? '',
                    'author' => $__b['author'] ?? '',
                    'description' => $__b['description'] ?? '',
                    'pdf_url' => $__b['file_url'] ?? '',
                    'cover_url' => $__b['cover_url'] ?? '',
                    'rating' => floatval($__b['avg_rating'] ?? 0),
                    'status' => 'available'
                ];
            }
            echo json_encode($__books_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
        ?>;

        let currentBookId = null;
        let currentBookData = null;

        // DOM Elements
        const modal = document.getElementById('book-modal');
        const closeModalBtn = document.getElementById('close-modal');
        const readPdfBtn = document.getElementById('read-pdf');
        const addToLibraryModalBtn = document.getElementById('add-to-library-modal');
        const viewReviewsModalBtn = document.getElementById('view-reviews-modal');

        // Open modal function
        function openBookModal(bookId) {
            currentBookId = bookId;
            currentBookData = booksData[bookId];
            
            if (currentBookData) {
                document.getElementById('modal-cover').src = currentBookData.cover_url || 'https://images.unsplash.com/photo-1544947950-fa07a98d237f';
                document.getElementById('modal-title').textContent = currentBookData.title;
                document.getElementById('modal-author').textContent = `by ${currentBookData.author}`;
                document.getElementById('modal-description').textContent = currentBookData.description || 'No description available for this book.';
                
                // Update rating stars
                const rating = currentBookData.rating || 0;
                document.getElementById('modal-rating-value').textContent = rating.toFixed(1);
                const starsContainer = document.getElementById('modal-stars');
                starsContainer.innerHTML = '';
                const fullStars = Math.floor(rating);
                const hasHalfStar = rating - fullStars >= 0.5;
                for (let i = 0; i < 5; i++) {
                    if (i < fullStars) {
                        starsContainer.innerHTML += '<i class="fas fa-star"></i>';
                    } else if (i === fullStars && hasHalfStar) {
                        starsContainer.innerHTML += '<i class="fas fa-star-half-alt"></i>';
                    } else {
                        starsContainer.innerHTML += '<i class="far fa-star"></i>';
                    }
                }
                
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        // Close modal
        function closeModal() {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Add to library function
        function addToLibrary(bookId) {
            if (!bookId) return;
            
            fetch('add_to_library.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'book_id=' + bookId + '&status=want_to_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Book added to your library!');
                } else {
                    alert('Error: ' + (data.message || 'Failed to add book'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to connect to server');
            });
        }

        // View reviews function
        function viewReviews(bookId) {
            window.location.href = `reviews.php?book_id=${bookId}`;
        }

        // Event Listeners
        closeModalBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
        });

        if (readPdfBtn) {
            readPdfBtn.addEventListener('click', () => {
                if (currentBookData && currentBookData.pdf_url) {
                    window.open(currentBookData.pdf_url, '_blank');
                } else {
                    alert('PDF URL not available for this book.');
                }
            });
        }

        if (addToLibraryModalBtn) {
            addToLibraryModalBtn.addEventListener('click', () => {
                if (currentBookId) addToLibrary(currentBookId);
            });
        }

        if (viewReviewsModalBtn) {
            viewReviewsModalBtn.addEventListener('click', () => {
                if (currentBookId) viewReviews(currentBookId);
            });
        }

        // Book card click handlers
        document.querySelectorAll('.book-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.closest('.book-action')) return;
                const bookId = card.getAttribute('data-book-id');
                if (bookId) openBookModal(bookId);
            });
        });

        // View details buttons
        document.querySelectorAll('.view-details').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const bookId = btn.closest('.book-card').getAttribute('data-book-id');
                if (bookId) openBookModal(bookId);
            });
        });

        // Add to library buttons in grid
        document.querySelectorAll('.add-to-library').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const bookId = btn.closest('.book-card').getAttribute('data-book-id');
                if (bookId) addToLibrary(bookId);
            });
        });

        // Share buttons
        document.querySelectorAll('.share-book').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const bookTitle = btn.closest('.book-card').querySelector('.book-title')?.textContent || 'this book';
                alert(`Sharing "${bookTitle}"`);
            });
        });

        // Search functionality
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    const query = searchInput.value.trim();
                    if (query) {
                        window.location.href = `catalog.php?search=${encodeURIComponent(query)}`;
                    }
                }
            });
        }

        // Newsletter form
        const newsletterForm = document.getElementById('newsletter-form');
        if (newsletterForm) {
            newsletterForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const email = newsletterForm.querySelector('input').value;
                if (email) {
                    alert(`Thank you for subscribing with ${email}! You'll receive our latest updates.`);
                    newsletterForm.reset();
                }
            });
        }

        console.log('Home page loaded with', Object.keys(booksData).length, 'books');
    </script>
</body>
</html>