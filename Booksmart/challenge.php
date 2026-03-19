<?php
// challenge.php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

// Database connection
require_once 'db_connect.php';
$uid = (int)$_SESSION['user_id'];

// Get user data
$user_stmt = $conn->prepare("SELECT name, avatar_url FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $uid);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();
$user_stmt->close();

// Set default avatar
$avatar_url = (!empty($user['avatar_url'])) ? $user['avatar_url'] : 'https://i.pravatar.cc/150?img=32';

// Get reading stats for current year
$current_year = date('Y');
$current_month = date('m');

// Books completed this year
$yearly_completed = $conn->query("
    SELECT COUNT(*) as count 
    FROM user_library 
    WHERE user_id = $uid 
    AND status = 'completed' 
    AND YEAR(last_opened) = $current_year
")->fetch_assoc()['count'];

// Books by month this year
$monthly_data = [];
$monthly_query = $conn->query("
    SELECT MONTH(last_opened) as month, COUNT(*) as count 
    FROM user_library 
    WHERE user_id = $uid 
    AND status = 'completed' 
    AND YEAR(last_opened) = $current_year
    GROUP BY MONTH(last_opened)
    ORDER BY month
");

if ($monthly_query) {
    while ($row = $monthly_query->fetch_assoc()) {
        $monthly_data[$row['month']] = $row['count'];
    }
}

// Get reading streak (consecutive days with reading activity)
$streak = 0;
$streak_query = $conn->query("
    SELECT DISTINCT DATE(last_opened) as read_date 
    FROM user_library 
    WHERE user_id = $uid 
    AND last_opened >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY read_date DESC
");

if ($streak_query && $streak_query->num_rows > 0) {
    $dates = [];
    while ($row = $streak_query->fetch_assoc()) {
        $dates[] = $row['read_date'];
    }
    
    // Calculate streak
    $today = date('Y-m-d');
    $current = $today;
    $streak = 0;
    
    while (in_array($current, $dates)) {
        $streak++;
        $current = date('Y-m-d', strtotime($current . ' -1 day'));
    }
}

// Get total unique reading days this year
$reading_days = $conn->query("
    SELECT COUNT(DISTINCT DATE(last_opened)) as count 
    FROM user_library 
    WHERE user_id = $uid 
    AND last_opened IS NOT NULL 
    AND YEAR(last_opened) = $current_year
")->fetch_assoc()['count'];

// Get total pages read (estimated - books don't have pages, so we'll use a default)
// Since your books don't have page counts, we'll estimate 300 pages per book
$pages_read = $yearly_completed * 300;

// Get favorite genre this year
$fav_genre = $conn->query("
    SELECT g.name as genre_name, COUNT(*) as count 
    FROM user_library ul 
    JOIN books b ON ul.book_id = b.book_id 
    JOIN genres g ON b.genre_id = g.genre_id
    WHERE ul.user_id = $uid 
    AND ul.status = 'completed' 
    AND YEAR(ul.last_opened) = $current_year
    AND b.genre_id IS NOT NULL 
    GROUP BY g.genre_id, g.name
    ORDER BY count DESC 
    LIMIT 1
")->fetch_assoc();
// Get favorite author this year
$fav_author = $conn->query("
    SELECT b.author, COUNT(*) as count 
    FROM user_library ul 
    JOIN books b ON ul.book_id = b.book_id 
    WHERE ul.user_id = $uid 
    AND ul.status = 'completed' 
    AND YEAR(ul.last_opened) = $current_year
    GROUP BY b.author 
    ORDER BY count DESC 
    LIMIT 1
")->fetch_assoc();

// Get recently completed books
$recent_completed = [];
$recent_query = $conn->query("
    SELECT ul.*, b.title, b.author, b.cover_url 
    FROM user_library ul 
    JOIN books b ON ul.book_id = b.book_id 
    WHERE ul.user_id = $uid 
    AND ul.status = 'completed' 
    AND YEAR(ul.last_opened) = $current_year
    ORDER BY ul.last_opened DESC 
    LIMIT 6
");

if ($recent_query) {
    while ($row = $recent_query->fetch_assoc()) {
        $recent_completed[] = $row;
    }
}

// Get all-time stats for comparison
$all_time_completed = $conn->query("
    SELECT COUNT(*) as count 
    FROM user_library 
    WHERE user_id = $uid 
    AND status = 'completed'
")->fetch_assoc()['count'];

// User's goal (you could store this in a user_preferences table, but for now we'll use a default or calculate)
// For now, let's set a dynamic goal based on last year's performance
$last_year_completed = $conn->query("
    SELECT COUNT(*) as count 
    FROM user_library 
    WHERE user_id = $uid 
    AND status = 'completed' 
    AND YEAR(last_opened) = " . ($current_year - 1)
)->fetch_assoc()['count'];

// Set goal: either 12 (1 per month) or last year + 20%, whichever is higher
$goal = max(12, ceil($last_year_completed * 1.2));
$goal_percentage = $goal > 0 ? min(100, round(($yearly_completed / $goal) * 100)) : 0;

function e($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}

function month_name($num) {
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Avg', 'Sep', 'Okt', 'Nov', 'Dec'];
    return $months[$num - 1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booksmart - Reading Challenge</title>
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
            --gold: #ffd700;
            --silver: #c0c0c0;
            --bronze: #cd7f32;
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

        /* Main Content */
        .challenge-container {
            max-width: 1200px;
            margin: 0 auto 60px;
            padding: 0 5%;
        }

        /* Goal Card */
        .goal-card {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 40px;
            margin-bottom: 40px;
            box-shadow: var(--box-shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .goal-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1) 0%, rgba(114, 9, 183, 0.1) 100%);
            border-radius: 50%;
            transform: translate(50px, -50px);
        }

        .goal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .goal-title {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            color: var(--dark);
        }

        .goal-year {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 10px 25px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .goal-stats {
            display: flex;
            gap: 40px;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }

        .goal-stat {
            flex: 1;
        }

        .goal-stat-label {
            color: var(--gray);
            margin-bottom: 10px;
            font-size: 1rem;
        }

        .goal-stat-value {
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
            margin-bottom: 5px;
        }

        .goal-stat-sub {
            color: var(--gray);
            font-size: 0.9rem;
        }

        .progress-container {
            margin-top: 20px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .progress-title {
            font-weight: 600;
        }

        .progress-percentage {
            font-weight: 700;
            color: var(--primary);
        }

        .progress-bar-big {
            height: 20px;
            background: var(--light-gray);
            border-radius: 30px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 30px;
            transition: width 1s ease;
        }

        .goal-message {
            text-align: center;
            padding: 20px;
            background: #f0f7ff;
            border-radius: var(--border-radius);
            color: var(--primary);
            font-weight: 500;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow-lg);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(67, 97, 238, 0.1), rgba(114, 9, 183, 0.1));
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            color: var(--primary);
            font-size: 24px;
        }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .stat-card p {
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Monthly Chart */
        .chart-section {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: var(--box-shadow);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
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

        .chart-container {
            display: flex;
            align-items: flex-end;
            gap: 15px;
            height: 250px;
            margin-top: 40px;
        }

        .chart-bar-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
        }

        .chart-bar {
            width: 100%;
            background: linear-gradient(to top, var(--primary), var(--secondary));
            border-radius: 10px 10px 0 0;
            transition: height 0.5s ease;
            position: relative;
            min-height: 5px;
        }

        .chart-label {
            margin-top: 15px;
            font-weight: 600;
            color: var(--dark);
        }

        .chart-value {
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: white;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Achievement Badges */
        .badges-section {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: var(--box-shadow);
        }

        .badges-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-top: 30px;
        }

        .badge {
            text-align: center;
            transition: var(--transition);
        }

        .badge-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            transition: var(--transition);
        }

        .badge.earned .badge-icon {
            background: linear-gradient(135deg, var(--gold), #ffb347);
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.3);
        }

        .badge:not(.earned) .badge-icon {
            background: #e0e0e0;
            color: #a0a0a0;
        }

        .badge h4 {
            font-size: 1rem;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .badge p {
            font-size: 0.8rem;
            color: var(--gray);
        }

        /* Recently Completed */
        .recent-section {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 30px;
            box-shadow: var(--box-shadow);
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .book-card {
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
        }

        .book-card:hover {
            transform: translateY(-5px);
        }

        .book-cover {
            width: 100%;
            height: 220px;
            border-radius: var(--border-radius);
            object-fit: cover;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }

        .book-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--dark);
            font-size: 1rem;
        }

        .book-author {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .book-date {
            font-size: 0.8rem;
            color: var(--primary);
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--border-radius-lg);
            grid-column: 1 / -1;
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

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
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

        /* Responsive */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .badges-grid {
                grid-template-columns: repeat(3, 1fr);
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
            
            .goal-stats {
                flex-direction: column;
                gap: 20px;
            }
            
            .chart-container {
                height: 200px;
            }
            
            .badges-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .goal-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
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
            <a href="reviews.php">Reviews</a>
            <a href="challenge.php" class="active">Challenge</a>
        </nav>
        
        <div class="header-actions">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search books...">
            </div>
            
            <div class="profile">
                <img src="<?php echo e($avatar_url); ?>" alt="Profile" onerror="this.src='https://i.pravatar.cc/150?img=32'">
                <div class="profile-dropdown">
                    <img src="<?php echo e($avatar_url); ?>" alt="Profile" onerror="this.src='https://i.pravatar.cc/150?img=32'">
                    <h3><?php echo e($user['name'] ?? 'User'); ?></h3>
                    <a href="profpage.php"><i class="fas fa-user"></i> My Profile</a>
                    <a href="mybooks.php"><i class="fas fa-bookmark"></i> My Library</a>
                    <a href="challenge.php"><i class="fas fa-trophy"></i> Reading Challenge</a>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Page Header -->
    <section class="page-header">
        <div class="page-header-content">
            <h1 class="page-title">Reading Challenge 2026</h1>
            <p class="page-subtitle">Set goals, track your progress, and earn badges as you complete your reading journey.</p>
        </div>
    </section>

    <!-- Main Content -->
    <div class="challenge-container">
        <!-- Goal Card -->
        <div class="goal-card">
            <div class="goal-header">
                <h2 class="goal-title">Godišnji cilj</h2>
                <span class="goal-year">2026</span>
            </div>
            
            <div class="goal-stats">
                <div class="goal-stat">
                    <div class="goal-stat-label">Cilj</div>
                    <div class="goal-stat-value"><?php echo $goal; ?></div>
                    <div class="goal-stat-sub">knjiga</div>
                </div>
                <div class="goal-stat">
                    <div class="goal-stat-label">Pročitano</div>
                    <div class="goal-stat-value" style="color: var(--success);"><?php echo $yearly_completed; ?></div>
                    <div class="goal-stat-sub">knjiga</div>
                </div>
                <div class="goal-stat">
                    <div class="goal-stat-label">Preostalo</div>
                    <div class="goal-stat-value" style="color: var(--warning);"><?php echo max(0, $goal - $yearly_completed); ?></div>
                    <div class="goal-stat-sub">knjiga</div>
                </div>
            </div>
            
            <div class="progress-container">
                <div class="progress-header">
                    <span class="progress-title">Napredak</span>
                    <span class="progress-percentage"><?php echo $goal_percentage; ?>%</span>
                </div>
                <div class="progress-bar-big">
                    <div class="progress-fill" style="width: <?php echo $goal_percentage; ?>%;"></div>
                </div>
                
                <?php if ($goal_percentage >= 100): ?>
                    <div class="goal-message">
                        <i class="fas fa-trophy" style="margin-right: 10px;"></i>
                        Čestitamo! Ostvario/la si svoj godišnji cilj! 🎉
                    </div>
                <?php elseif ($goal - $yearly_completed <= 3 && $goal - $yearly_completed > 0): ?>
                    <div class="goal-message">
                        <i class="fas fa-rocket" style="margin-right: 10px;"></i>
                        Samo još <?php echo $goal - $yearly_completed; ?> knjiga do cilja! Možeš ti to! 💪
                    </div>
                <?php else: ?>
                    <div class="goal-message">
                        <i class="fas fa-book-open" style="margin-right: 10px;"></i>
                        Treba ti još <?php echo max(0, $goal - $yearly_completed); ?> knjiga do cilja. Nastavi čitati!
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h3><?php echo $reading_days; ?></h3>
                <p>Dana čitanja</p>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-fire"></i>
                </div>
                <h3><?php echo $streak; ?></h3>
                <p>Dana u nizu</p>
                <?php if ($streak > 0): ?>
                    <small style="color: var(--gray);">trenutno</small>
                <?php endif; ?>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <h3><?php echo number_format($pages_read); ?></h3>
                <p>Stranica pročitano</p>
                <small style="color: var(--gray);">(procjena)</small>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <h3><?php echo $goal > 0 ? round($yearly_completed / $goal * 100) : 0; ?>%</h3>
                <p>od cilja</p>
            </div>
        </div>

        <!-- Monthly Chart -->
        <div class="chart-section">
            <div class="section-header">
                <h2 class="section-title">Mjesečni pregled</h2>
                <span class="badge" style="background: var(--light); padding: 8px 15px; border-radius: 30px;">
                    <i class="fas fa-book" style="color: var(--primary);"></i> <?php echo array_sum($monthly_data); ?> knjiga
                </span>
            </div>
            
            <div class="chart-container">
                <?php 
                $max_books = max(1, max($monthly_data ?: [1]));
                for ($m = 1; $m <= 12; $m++): 
                    $count = $monthly_data[$m] ?? 0;
                    $height = ($count / $max_books) * 200;
                ?>
                    <div class="chart-bar-wrapper">
                        <div class="chart-bar" style="height: <?php echo $height; ?>px;">
                            <?php if ($count > 0): ?>
                                <span class="chart-value"><?php echo $count; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="chart-label"><?php echo month_name($m); ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Achievement Badges -->
        <div class="badges-section">
            <div class="section-header">
                <h2 class="section-title">Ostvareni bedževi</h2>
                <span class="badge" style="background: var(--light); padding: 8px 15px; border-radius: 30px;">
                    <i class="fas fa-star" style="color: var(--gold);"></i> 
                    <?php 
                    $earned_badges = 0;
                    if ($yearly_completed >= 5) $earned_badges++;
                    if ($yearly_completed >= 12) $earned_badges++;
                    if ($yearly_completed >= 25) $earned_badges++;
                    if ($yearly_completed >= 50) $earned_badges++;
                    if ($streak >= 7) $earned_badges++;
                    echo $earned_badges; 
                    ?>/5
                </span>
            </div>
            
            <div class="badges-grid">
                <!-- Bronze Reader - 5 books -->
                <div class="badge <?php echo $yearly_completed >= 5 ? 'earned' : ''; ?>">
                    <div class="badge-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <h4>Bronze reader</h5>
                    <p>5 pročitanih knjiga</p>
                </div>
                
                <!-- Silver Reader - 12 books -->
                <div class="badge <?php echo $yearly_completed >= 12 ? 'earned' : ''; ?>">
                    <div class="badge-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <h4>Silver reader</h5>
                    <p>12 pročitanih knjiga</p>
                </div>
                
                <!-- Gold Reader - 25 books -->
                <div class="badge <?php echo $yearly_completed >= 25 ? 'earned' : ''; ?>">
                    <div class="badge-icon">
                        <i class="fas fa-crown"></i>
                    </div>
                    <h4>Golden reader</h5>
                    <p>25 pročitanih knjiga</p>
                </div>
                
                <!-- Platinum Reader - 50 books -->
                <div class="badge <?php echo $yearly_completed >= 50 ? 'earned' : ''; ?>">
                    <div class="badge-icon">
                        <i class="fas fa-crown"></i>
                    </div>
                    <h4>Platinum reader</h5>
                    <p>50 pročitanih knjiga</p>
                </div>
                
                <!-- Streak Master - 7 days -->
                <div class="badge <?php echo $streak >= 7 ? 'earned' : ''; ?>">
                    <div class="badge-icon">
                        <i class="fas fa-fire"></i>
                    </div>
                    <h4>Niz od 7 dana</h5>
                    <p>Čitaj 7 dana zaredom</p>
                </div>
            </div>
        </div>

        <!-- Recently Completed -->
        <div class="recent-section">
            <div class="section-header">
                <h2 class="section-title">Nedavno pročitano</h2>
                <a href="mybooks.php?status=completed" class="btn-outline btn" style="padding: 8px 20px;">Pogledaj sve</a>
            </div>
            
            <?php if (empty($recent_completed)): ?>
                <div class="empty-state">
                    <i class="fas fa-book-open"></i>
                    <h3>Još nema pročitanih knjiga</h3>
                    <p>Počni čitati i prati svoj napredak!</p>
                    <a href="catalog.php" class="btn-primary btn">
                        <i class="fas fa-search"></i> Pronađi knjigu
                    </a>
                </div>
            <?php else: ?>
                <div class="books-grid">
                    <?php foreach ($recent_completed as $book): ?>
                        <div class="book-card" onclick="window.location.href='catalog.php?book_id=<?php echo $book['book_id']; ?>'">
                            <img class="book-cover" src="<?php echo e($book['cover_url'] ?: 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($book['title']); ?>">
                            <h4 class="book-title"><?php echo e(strlen($book['title']) > 30 ? substr($book['title'], 0, 30) . '...' : $book['title']); ?></h4>
                            <p class="book-author"><?php echo e($book['author']); ?></p>
                            <div class="book-date">
                                <i class="far fa-calendar-alt"></i> 
                                <?php echo date('d.m.Y', strtotime($book['last_opened'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                    <li><a href="mybooks.php">My Library</a></li>
                    <li><a href="challenge.php">Reading Challenge</a></li>
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
            <p>&copy; 2025 Booksmart. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Profile dropdown
        const profile = document.querySelector('.profile');
        if (profile) {
            profile.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('active');
            });
        }
        
        document.addEventListener('click', function() {
            if (profile) profile.classList.remove('active');
        });

        // Search functionality
        document.querySelector('.search-bar input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const query = this.value.trim();
                if (query) {
                    window.location.href = 'catalog.php?search=' + encodeURIComponent(query);
                }
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>