<?php
// reviews.php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

// Database connection
require_once 'db_connect.php';

// Get user data
$user_data = null;
$user_stmt = $conn->prepare("SELECT user_id, name, email, avatar_url FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
if ($user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
}
$user_stmt->close();

// Set default avatar if none exists
$avatar_url = (!empty($user_data['avatar_url'])) ? $user_data['avatar_url'] : 'https://i.pravatar.cc/150?img=32';

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'recent';
$book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;

// Build query based on filters
$query = "SELECT r.*, 
                 u.name as user_name, 
                 u.avatar_url as user_avatar,
                 b.title as book_title,
                 b.author as book_author,
                 b.cover_url as book_cover,
                 b.book_id
          FROM reviews r 
          JOIN users u ON r.user_id = u.user_id 
          JOIN books b ON r.book_id = b.book_id";

if ($book_id > 0) {
    $query .= " WHERE r.book_id = " . $book_id;
}

// Apply sorting
switch ($filter) {
    case 'highest':
        $query .= " ORDER BY r.rating DESC, r.created_at DESC";
        break;
    case 'lowest':
        $query .= " ORDER BY r.rating ASC, r.created_at DESC";
        break;
    case 'recent':
    default:
        $query .= " ORDER BY r.created_at DESC";
        break;
}

$query .= " LIMIT 30";

$reviews = [];
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    $result->close();
}

// Get book info if filtering by book
$book_info = null;
if ($book_id > 0) {
    $book_stmt = $conn->prepare("SELECT * FROM books WHERE book_id = ?");
    $book_stmt->bind_param("i", $book_id);
    $book_stmt->execute();
    $book_result = $book_stmt->get_result();
    $book_info = $book_result->fetch_assoc();
    $book_stmt->close();
}

// Get popular books for sidebar
$popular_books = [];
$pop_query = "SELECT b.book_id, b.title, b.author, b.cover_url, 
                     COUNT(r.review_id) as review_count,
                     AVG(r.rating) as avg_rating
              FROM books b
              LEFT JOIN reviews r ON b.book_id = r.book_id
              GROUP BY b.book_id
              HAVING review_count > 0
              ORDER BY review_count DESC, avg_rating DESC
              LIMIT 5";
$pop_result = $conn->query($pop_query);
if ($pop_result) {
    while ($row = $pop_result->fetch_assoc()) {
        $popular_books[] = $row;
    }
    $pop_result->close();
}

function e($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booksmart - Reviews Community</title>
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
            --danger: #ef233c;
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
            min-height: 100vh;
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
        }

        .search-bar input {
            padding: 12px 20px 12px 45px;
            border-radius: 30px;
            border: 1px solid var(--light-gray);
            background: var(--light);
            font-size: 1em;
            width: 300px;
            transition: var(--transition);
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
            top: 50%;
            transform: translateY(-50%);
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
            min-width: 200px;
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

        /* Page Header */
        .page-header {
            padding: 60px 5% 40px;
            background: linear-gradient(135deg, #4361ee 0%, #7209b7 100%);
            color: white;
            border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" opacity="0.05"><path fill="white" d="M500,100 C700,50 900,150 900,350 C900,550 700,650 500,600 C300,650 100,550 100,350 C100,150 300,50 500,100 Z"/></svg>');
            background-size: cover;
        }

        .page-header-content {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .page-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            max-width: 600px;
        }

        /* Book Info Banner (when filtering by book) */
        .book-banner {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin: -20px 5% 40px;
            box-shadow: var(--box-shadow-lg);
            display: flex;
            gap: 30px;
            align-items: center;
            position: relative;
            z-index: 10;
        }

        .book-banner-cover {
            width: 100px;
            height: 140px;
            border-radius: var(--border-radius);
            object-fit: cover;
            box-shadow: var(--box-shadow);
        }

        .book-banner-info {
            flex: 1;
        }

        .book-banner-title {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            margin-bottom: 5px;
        }

        .book-banner-author {
            color: var(--gray);
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .book-banner-stats {
            display: flex;
            gap: 30px;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-item i {
            color: var(--warning);
        }

        .clear-filter {
            padding: 10px 20px;
            background: var(--light-gray);
            border-radius: 30px;
            text-decoration: none;
            color: var(--dark);
            font-weight: 500;
            transition: var(--transition);
        }

        .clear-filter:hover {
            background: var(--primary);
            color: white;
        }

        /* Main Content Layout */
        .main-content {
            display: flex;
            gap: 40px;
            padding: 0 5% 60px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .reviews-section {
            flex: 2;
        }

        .sidebar {
            flex: 1;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: white;
            padding: 15px 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
        }

        .filter-tab {
            padding: 8px 20px;
            border-radius: 30px;
            text-decoration: none;
            color: var(--gray);
            font-weight: 500;
            transition: var(--transition);
            background: var(--light);
        }

        .filter-tab:hover {
            background: var(--primary-light);
            color: white;
        }

        .filter-tab.active {
            background: var(--primary);
            color: white;
        }

        .review-count {
            color: var(--gray);
            font-weight: 500;
        }

        /* Review Cards */
        .review-card {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .review-header {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .reviewer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
        }

        .reviewer-info {
            flex: 1;
        }

        .reviewer-name {
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .review-meta {
            display: flex;
            gap: 15px;
            color: var(--gray);
            font-size: 0.9rem;
        }

        .review-rating {
            display: flex;
            gap: 5px;
            margin-bottom: 10px;
        }

        .review-rating i {
            color: var(--warning);
            font-size: 1.1rem;
        }

        .review-rating i.far {
            color: var(--light-gray);
        }

        .review-content {
            margin-bottom: 20px;
            line-height: 1.7;
            color: var(--dark);
        }

        .review-book {
            display: flex;
            gap: 15px;
            align-items: center;
            background: var(--light);
            padding: 15px;
            border-radius: var(--border-radius);
            margin-top: 15px;
        }

        .review-book-cover {
            width: 50px;
            height: 70px;
            border-radius: 8px;
            object-fit: cover;
        }

        .review-book-info h4 {
            font-weight: 600;
            margin-bottom: 3px;
        }

        .review-book-info p {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .review-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--light-gray);
        }

        .review-action-btn {
            padding: 8px 20px;
            border-radius: 30px;
            border: none;
            background: var(--light);
            color: var(--dark);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .review-action-btn:hover {
            background: var(--primary);
            color: white;
        }

        .review-action-btn.delete:hover {
            background: var(--danger);
            color: white;
        }

        /* Sidebar Widgets */
        .sidebar-widget {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }

        .widget-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
        }

        .widget-title::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--primary);
            border-radius: 2px;
        }

        .popular-book-item {
            display: flex;
            gap: 15px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--light-gray);
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
        }

        .popular-book-item:last-child {
            border-bottom: none;
        }

        .popular-book-item:hover {
            transform: translateX(5px);
        }

        .popular-book-cover {
            width: 40px;
            height: 55px;
            border-radius: 6px;
            object-fit: cover;
        }

        .popular-book-info h4 {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 3px;
        }

        .popular-book-info p {
            color: var(--gray);
            font-size: 0.8rem;
        }

        .popular-book-stats {
            display: flex;
            gap: 10px;
            margin-top: 5px;
            font-size: 0.8rem;
        }

        .popular-book-stats i {
            color: var(--warning);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 20px;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--border-radius-lg);
        }

        .empty-state i {
            font-size: 4rem;
            color: var(--light-gray);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--gray);
            margin-bottom: 20px;
        }

        .btn {
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3);
        }

        /* Footer */
        footer {
            background: var(--dark);
            color: white;
            padding: 60px 5% 30px;
            margin-top: 40px;
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

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                flex-direction: column;
            }
            
            .book-banner {
                flex-direction: column;
                text-align: center;
            }
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
            }
            
            .search-bar input {
                width: 200px;
            }
            
            .page-title {
                font-size: 2.5rem;
            }
            
            .filter-bar {
                flex-direction: column;
                gap: 15px;
            }
            
            .review-header {
                flex-direction: column;
                text-align: center;
            }
            
            .reviewer-avatar {
                margin: 0 auto;
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
            <a href="home.php">Home</a>
            <a href="catalog.php">Catalog</a>
            <a href="mybooks.php">My Books</a>
            <a href="reviews.php" class="active">Reviews</a>
            <a href="challenge.php">Challenge</a>
        </nav>
        
        <div class="header-actions">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search reviews, books...">
            </div>
            
            <div class="profile">
                <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Profile" onerror="this.src='https://i.pravatar.cc/150?img=32'">
                <div class="profile-dropdown">
                    <img src="<?php echo htmlspecialchars($avatar_url); ?>" alt="Profile" onerror="this.src='https://i.pravatar.cc/150?img=32'">
                    <h3><?php echo htmlspecialchars($user_data['name'] ?? 'User'); ?></h3>
                    <a href="profpage.php"><i class="fas fa-user"></i> My Profile</a>
                    <a href="#"><i class="fas fa-bookmark"></i> My Library</a>
                    <a href="#"><i class="fas fa-cog"></i> Settings</a>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Page Header -->
    <section class="page-header">
        <div class="page-header-content">
            <h1 class="page-title">Community Reviews</h1>
            <p class="page-subtitle">Discover what readers are saying about their favorite books. Join the conversation!</p>
        </div>
    </section>

    <?php if ($book_info): ?>
    <!-- Book Banner -->
    <div class="book-banner">
        <img src="<?php echo e($book_info['cover_url'] ?: 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($book_info['title']); ?>" class="book-banner-cover">
        <div class="book-banner-info">
            <h2 class="book-banner-title"><?php echo e($book_info['title']); ?></h2>
            <p class="book-banner-author">by <?php echo e($book_info['author']); ?></p>
            <?php
            $book_stats = $conn->query("SELECT COUNT(*) as count, AVG(rating) as avg FROM reviews WHERE book_id = " . $book_info['book_id'])->fetch_assoc();
            ?>
            <div class="book-banner-stats">
                <div class="stat-item">
                    <i class="fas fa-star"></i>
                    <span><?php echo number_format($book_stats['avg'] ?? 0, 1); ?> average</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-comments"></i>
                    <span><?php echo $book_stats['count']; ?> reviews</span>
                </div>
            </div>
        </div>
        <a href="reviews.php" class="clear-filter"><i class="fas fa-times"></i> Clear Filter</a>
    </div>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Reviews Section -->
        <div class="reviews-section">
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="filter-tabs">
                    <a href="?filter=recent<?php echo $book_id ? '&book_id=' . $book_id : ''; ?>" class="filter-tab <?php echo $filter == 'recent' ? 'active' : ''; ?>">Most Recent</a>
                    <a href="?filter=highest<?php echo $book_id ? '&book_id=' . $book_id : ''; ?>" class="filter-tab <?php echo $filter == 'highest' ? 'active' : ''; ?>">Highest Rated</a>
                    <a href="?filter=lowest<?php echo $book_id ? '&book_id=' . $book_id : ''; ?>" class="filter-tab <?php echo $filter == 'lowest' ? 'active' : ''; ?>">Lowest Rated</a>
                </div>
                <span class="review-count"><?php echo count($reviews); ?> reviews</span>
            </div>

            <!-- Reviews List -->
            <?php if (empty($reviews)): ?>
                <div class="empty-state">
                    <i class="fas fa-comments"></i>
                    <h3>No Reviews Yet</h3>
                    <p>Be the first to share your thoughts about this book!</p>
                    <a href="catalog.php" class="btn btn-primary"><i class="fas fa-book-open"></i> Browse Books</a>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                    <div class="review-card" data-review-id="<?php echo $review['review_id']; ?>">
                        <div class="review-header">
                            <img src="<?php echo e($review['user_avatar'] ?: 'https://i.pravatar.cc/150?img=' . rand(1, 70)); ?>" alt="<?php echo e($review['user_name']); ?>" class="reviewer-avatar">
                            <div class="reviewer-info">
                                <h3 class="reviewer-name"><?php echo e($review['user_name']); ?></h3>
                                <div class="review-meta">
                                    <span><i class="far fa-clock"></i> <?php echo time_ago($review['created_at']); ?></span>
                                </div>
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

                        <div class="review-content">
                            <?php echo nl2br(e($review['comment'])); ?>
                        </div>

                        <?php if (!$book_info): ?>
                        <a href="reviews.php?book_id=<?php echo $review['book_id']; ?>" class="review-book">
                            <img src="<?php echo e($review['book_cover'] ?: 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($review['book_title']); ?>" class="review-book-cover">
                            <div class="review-book-info">
                                <h4><?php echo e($review['book_title']); ?></h4>
                                <p>by <?php echo e($review['book_author']); ?></p>
                            </div>
                        </a>
                        <?php endif; ?>

                        <?php if ($review['user_id'] == $_SESSION['user_id']): ?>
                        <div class="review-actions">
                            <button class="review-action-btn" onclick="editReview(<?php echo $review['review_id']; ?>, <?php echo $review['book_id']; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button class="review-action-btn delete" onclick="deleteReview(<?php echo $review['review_id']; ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Stats Widget -->
            <div class="sidebar-widget">
                <h3 class="widget-title">Community Stats</h3>
                <?php
                $total_reviews = $conn->query("SELECT COUNT(*) as count FROM reviews")->fetch_assoc()['count'];
                $avg_rating = $conn->query("SELECT AVG(rating) as avg FROM reviews")->fetch_assoc()['avg'];
                $total_users = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
                ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_reviews; ?></div>
                        <div class="stat-label">Reviews</div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, var(--secondary) 0%, var(--accent) 100%);">
                        <div class="stat-number"><?php echo number_format($avg_rating, 1); ?></div>
                        <div class="stat-label">Avg Rating</div>
                    </div>
                </div>
            </div>

            <!-- Popular Books Widget -->
            <?php if (!empty($popular_books)): ?>
            <div class="sidebar-widget">
                <h3 class="widget-title">Most Reviewed</h3>
                <?php foreach ($popular_books as $book): ?>
                    <a href="reviews.php?book_id=<?php echo $book['book_id']; ?>" class="popular-book-item">
                        <img src="<?php echo e($book['cover_url'] ?: 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($book['title']); ?>" class="popular-book-cover">
                        <div class="popular-book-info">
                            <h4><?php echo e(strlen($book['title']) > 30 ? substr($book['title'], 0, 30) . '...' : $book['title']); ?></h4>
                            <p><?php echo e($book['author']); ?></p>
                            <div class="popular-book-stats">
                                <span><i class="fas fa-star"></i> <?php echo number_format($book['avg_rating'], 1); ?></span>
                                <span><i class="fas fa-comment"></i> <?php echo $book['review_count']; ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Recent Reviewers Widget -->
            <div class="sidebar-widget">
                <h3 class="widget-title">Active Today</h3>
                <?php
                $active_users = $conn->query("
                    SELECT DISTINCT u.name, u.avatar_url, COUNT(r.review_id) as review_count 
                    FROM users u 
                    JOIN reviews r ON u.user_id = r.user_id 
                    WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY u.user_id 
                    ORDER BY review_count DESC 
                    LIMIT 5
                ");
                if ($active_users && $active_users->num_rows > 0):
                    while ($user = $active_users->fetch_assoc()):
                ?>
                <div class="popular-book-item" style="cursor: default;">
                    <img src="<?php echo e($user['avatar_url'] ?: 'https://i.pravatar.cc/150?img=' . rand(1, 70)); ?>" alt="<?php echo e($user['name']); ?>" class="popular-book-cover" style="border-radius: 50%; width: 40px; height: 40px;">
                    <div class="popular-book-info">
                        <h4><?php echo e($user['name']); ?></h4>
                        <p><?php echo $user['review_count']; ?> reviews today</p>
                    </div>
                </div>
                <?php 
                    endwhile;
                else:
                ?>
                <p style="color: var(--gray); text-align: center;">No reviews in the last 24 hours</p>
                <?php endif; ?>
            </div>
        </div>
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
                    <li><a href="challenge.php">Challenge</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Account</h3>
                <ul class="footer-links">
                    <li><a href="profpage.php">Profile</a></li>
                    <li><a href="#">My Library</a></li>
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
            <p>&copy; 2023 Booksmart. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Review functions
        function editReview(reviewId, bookId) {
            // Redirect to catalog with book modal open
            window.location.href = `catalog.php?review=edit&book_id=${bookId}&review_id=${reviewId}`;
        }

        function deleteReview(reviewId) {
            if (confirm('Are you sure you want to delete this review?')) {
                fetch('delete_review.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'review_id=' + reviewId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Review deleted successfully');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to delete review');
                });
            }
        }

        // Search functionality
        document.querySelector('.search-bar input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query) {
                    window.location.href = `catalog.php?search=${encodeURIComponent(query)}`;
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>