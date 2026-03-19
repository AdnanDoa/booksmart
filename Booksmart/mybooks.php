<?php
// mybooks.php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

// Database connection
$conn = require_once 'db_connect.php';

// Get user data
$user_data = null;
$user_stmt = $conn->prepare("SELECT user_id, name, email, avatar_url, bio FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $_SESSION['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
if ($user_result->num_rows > 0) {
    $user_data = $user_result->fetch_assoc();
}
$user_stmt->close();

// Set default avatar if none exists
$avatar_url = (!empty($user_data['avatar_url'])) ? $user_data['avatar_url'] : 'https://i.pravatar.cc/150?img=32';

// Get user's books from library (books that exist in both books and user_library tables)
$user_books = [];
$library_stmt = $conn->prepare("
    SELECT b.book_id, b.title, b.author, b.description, b.cover_url, bf.file_url, 
           ul.status, ul.last_opened, ul.progress, ul.date_added
    FROM books b
    JOIN user_library ul ON b.book_id = ul.book_id
    JOIN book_files bf ON b.book_id = bf.book_id 
    WHERE ul.user_id = ? AND bf.file_type = 'pdf'
    ORDER BY ul.last_opened DESC
");
$library_stmt->bind_param("i", $_SESSION['user_id']);
$library_stmt->execute();
$library_result = $library_stmt->get_result();
if ($library_result) {
    while ($row = $library_result->fetch_assoc()) {
        $user_books[] = $row;
    }
}
$library_stmt->close();

// Get reading statistics
$stats = [
    'total_books' => count($user_books),
    'currently_reading' => 0,
    'completed' => 0,
    'want_to_read' => 0
];

foreach ($user_books as $book) {
    switch ($book['status']) {
        case 'reading':
            $stats['currently_reading']++;
            break;
        case 'completed':
            $stats['completed']++;
            break;
        case 'want_to_read':
            $stats['want_to_read']++;
            break;
    }
}

function e($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Books - Booksmart Library</title>
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
            --danger: #f72585;
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
            padding: 60px 5%;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 30px;
        }

        .page-header-text {
            flex: 1;
            min-width: 300px;
        }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .page-subtitle {
            font-size: 1.2rem;
            margin-bottom: 30px;
            opacity: 0.9;
            max-width: 600px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 30px;
            min-width: 280px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Library Controls */
        .library-controls {
            padding: 0 5% 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 10px 20px;
            border-radius: 30px;
            background: white;
            border: 1px solid var(--light-gray);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-tab:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .filter-tab.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .filter-tab i {
            font-size: 0.9rem;
        }

        .sort-select {
            padding: 10px 20px;
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: 30px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sort-select:hover {
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.1);
        }

        /* Books Grid */
        .section {
            padding: 0 5% 60px;
        }

        .books-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
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
            display: flex;
            flex-direction: column;
        }

        .book-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--box-shadow-lg);
        }

        .book-cover {
            position: relative;
            overflow: hidden;
            height: 350px;
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

        .book-action.delete {
            background: var(--danger);
        }

        .book-action.delete:hover {
            background: #d1146b;
        }

        .book-info {
            padding: 20px;
            flex: 1;
        }

        .book-title {
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: var(--dark);
            line-height: 1.3;
        }

        .book-author {
            color: var(--gray);
            font-size: 0.95rem;
            margin-bottom: 15px;
        }

        .book-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .book-rating {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--warning);
        }

        .book-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-reading {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
        }

        .status-completed {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
        }

        .status-want_to_read {
            background: rgba(255, 158, 0, 0.2);
            color: var(--warning);
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--light-gray);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        .date-added {
            font-size: 0.8rem;
            color: var(--gray);
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .date-added i {
            font-size: 0.7rem;
        }

        /* Empty State */
        .empty-library {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            background: white;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--box-shadow);
        }

        .empty-icon {
            font-size: 5rem;
            color: var(--primary-light);
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-title {
            font-family: 'Playfair Display', serif;
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 15px;
        }

        .empty-text {
            color: var(--gray);
            margin-bottom: 30px;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
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
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-secondary:hover {
            background: rgba(67, 97, 238, 0.1);
            transform: translateY(-3px);
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
            animation: modalFadeIn 0.4s ease;
        }

        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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
            border: 1px solid rgba(255, 255, 255, 0.2);
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

        .modal-cover-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000" opacity="0.05"><path fill="white" d="M500,100 C700,50 900,150 900,350 C900,550 700,650 500,600 C300,650 100,550 100,350 C100,150 300,50 500,100 Z"/></svg>');
            background-size: cover;
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

        .cover-container:hover #modal-cover {
            transform: translateY(-10px) rotateY(5deg);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4);
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
            font-size: 2.2rem;
            margin-bottom: 10px;
            color: var(--dark);
            line-height: 1.2;
        }

        #modal-author {
            font-size: 1.2rem;
            color: var(--gray);
            margin-bottom: 20px;
            font-style: italic;
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
        }

        .stars i {
            color: var(--warning);
            font-size: 1.2rem;
        }

        .rating-value {
            font-weight: 600;
            color: var(--dark);
        }

        .rating-count {
            color: var(--gray);
            font-size: 0.9rem;
        }

        #modal-status {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 25px;
            align-self: flex-start;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .modal-progress {
            width: 100%;
            margin-bottom: 20px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
            color: var(--gray);
        }

        .progress-slider {
            width: 100%;
            height: 8px;
            background: var(--light-gray);
            border-radius: 4px;
            outline: none;
            -webkit-appearance: none;
        }

        .progress-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            border: 2px solid white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .modal-description {
            margin-bottom: 30px;
            line-height: 1.7;
            color: var(--gray);
        }

        .modal-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 30px;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .meta-label {
            font-size: 0.8rem;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .meta-value {
            font-weight: 600;
            color: var(--dark);
        }

        .modal-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-btn {
            flex: 1;
            min-width: 120px;
            padding: 14px 20px;
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

        .action-btn.danger {
            background: var(--danger);
            color: white;
        }

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
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
            
            .page-title {
                font-size: 2.5rem;
            }
            
            .page-header-content {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                width: 100%;
            }
            
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            }
        }

        @media (max-width: 576px) {
            .books-grid {
                grid-template-columns: 1fr;
            }
            
            .book-cover {
                height: 300px;
            }
            
            .library-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-tabs {
                justify-content: center;
            }
        }

        /* Animation for modal */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        .modal-content {
            animation: float 6s ease-in-out infinite;
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
            <a href="mybooks.php" class="active">My Books</a>
            <a href="reviews.php">Reviews</a>
            <a href="challenge.php">Challenge</a>
        </nav>
        
        <div class="header-actions">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search your books...">
            </div>
            
            <div class="profile">
                <img src="<?php echo e($avatar_url); ?>" alt="Profile" onerror="this.src='https://i.pravatar.cc/150?img=32'">
                <div class="profile-dropdown">
                    <img src="<?php echo e($avatar_url); ?>" alt="Profile" onerror="this.src='https://i.pravatar.cc/150?img=32'">
                    <h3><?php echo e($user_data['name'] ?? 'User'); ?></h3>
                    <a href="profpage.php"><i class="fas fa-user"></i> My Profile</a>
                    <a href="#"><i class="fas fa-bookmark"></i> My Library</a>
                    <a href="#"><i class="fas fa-cog"></i> Settings</a>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Page Header with Stats -->
    <section class="page-header">
        <div class="page-header-content">
            <div class="page-header-text">
                <h1 class="page-title">My Library</h1>
                <p class="page-subtitle">Track your reading journey, manage your collection, and discover new favorites.</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['total_books']; ?></div>
                    <div class="stat-label">Total Books</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['currently_reading']; ?></div>
                    <div class="stat-label">Reading</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $stats['want_to_read']; ?></div>
                    <div class="stat-label">Want to Read</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Library Controls -->
    <div class="library-controls">
        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">
                <i class="fas fa-books"></i> All Books
            </button>
            <button class="filter-tab" data-filter="reading">
                <i class="fas fa-book-open"></i> Currently Reading
            </button>
            <button class="filter-tab" data-filter="completed">
                <i class="fas fa-check-circle"></i> Completed
            </button>
            <button class="filter-tab" data-filter="want_to_read">
                <i class="fas fa-bookmark"></i> Want to Read
            </button>
        </div>
        
        <div class="sort-select">
            <i class="fas fa-sort"></i>
            <select id="sort-books" style="border: none; background: transparent; outline: none;">
                <option value="recent">Recently Added</option>
                <option value="title">Title A-Z</option>
                <option value="author">Author A-Z</option>
                <option value="progress">Progress</option>
            </select>
        </div>
    </div>

    <!-- Books Grid -->
    <section class="section">
        <div class="books-grid" id="books-grid">
            <?php if (!empty($user_books)): ?>
                <?php foreach ($user_books as $book): ?>
                    <div class='book-card' data-book-id='<?php echo e($book['book_id']); ?>' data-status='<?php echo e($book['status']); ?>' data-title='<?php echo e(strtolower($book['title'])); ?>' data-author='<?php echo e(strtolower($book['author'])); ?>' data-progress='<?php echo e($book['progress']); ?>' data-date='<?php echo e($book['date_added']); ?>'>
                        <div class='book-cover'>
                            <img src='<?php echo e($book['cover_url']); ?>' alt='<?php echo e($book['title']); ?>' onerror="this.src='https://images.unsplash.com/photo-1544947950-fa07a98d237f?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80'">
                            <div class='book-overlay'>
                                <button class='book-action view-details'><i class='fas fa-eye'></i></button>
                                <button class='book-action'><i class='fas fa-pen'></i></button>
                                <button class='book-action delete' onclick="removeFromLibrary(<?php echo e($book['book_id']); ?>, event)"><i class='fas fa-trash'></i></button>
                            </div>
                        </div>
                        <div class='book-info'>
                            <h3 class='book-title'><?php echo e($book['title']); ?></h3>
                            <p class='book-author'><?php echo e($book['author']); ?></p>
                            <div class='book-meta'>
                                <div class='book-rating'>
                                    <i class='fas fa-star'></i>
                                    <span>4.5</span>
                                </div>
                                <span class='book-status status-<?php echo e($book['status']); ?>'>
                                    <?php 
                                        switch($book['status']) {
                                            case 'reading':
                                                echo 'Reading';
                                                break;
                                            case 'completed':
                                                echo 'Completed';
                                                break;
                                            case 'want_to_read':
                                                echo 'Want to Read';
                                                break;
                                            default:
                                                echo ucfirst($book['status']);
                                        }
                                    ?>
                                </span>
                            </div>
                            <div class='progress-bar'>
                                <div class='progress-fill' style='width: <?php echo e($book['progress']); ?>%;'></div>
                            </div>
                            <div class='date-added'>
                                <i class='far fa-calendar-alt'></i>
                                Added <?php echo date('M j, Y', strtotime($book['date_added'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-library">
                    <div class="empty-icon">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h2 class="empty-title">Your library is empty</h2>
                    <p class="empty-text">Start building your personal collection by adding books from our catalog. Track your reading progress and discover new favorites!</p>
                    <a href="catalog.php" class="btn btn-primary">
                        <i class="fas fa-search"></i> Browse Catalog
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Book Details Modal -->
    <div id="book-modal">
        <div class="modal-content">
            <button class="modal-close" id="close-modal">
                <i class="fas fa-times"></i>
            </button>
            <div class="modal-body">
                <div class="modal-cover-section">
                    <div class="cover-container">
                        <img id="modal-cover" src="" alt="Book Cover">
                        <div class="cover-overlay"></div>
                    </div>
                </div>
                <div class="modal-details-section">
                    <h2 id="modal-title">Book Title</h2>
                    <p id="modal-author">by Author Name</p>
                    
                    <div class="modal-rating">
                        <div class="stars">
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star"></i>
                            <i class="fas fa-star-half-alt"></i>
                        </div>
                        <span class="rating-value">4.5</span>
                        <span class="rating-count">(12,345 reviews)</span>
                    </div>
                    
                    <span id="modal-status" class="status-reading">Currently Reading</span>
                    
                    <div class="modal-progress">
                        <div class="progress-header">
                            <span>Reading Progress</span>
                            <span id="progress-value">0%</span>
                        </div>
                        <input type="range" id="progress-slider" class="progress-slider" min="0" max="100" value="0">
                    </div>
                    
                    <p class="modal-description" id="modal-description">
                        Book description will appear here...
                    </p>
                    
                    <div class="modal-meta">
                        <div class="meta-item">
                            <span class="meta-label">Published</span>
                            <span class="meta-value" id="modal-published">Unknown</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Pages</span>
                            <span class="meta-value" id="modal-pages">Unknown</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Genre</span>
                            <span class="meta-value" id="modal-genre">Unknown</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Format</span>
                            <span class="meta-value" id="modal-format">PDF</span>
                        </div>
                    </div>
                    
                    <div class="modal-actions">
                        <button class="action-btn primary" id="read-pdf">
                            <i class="fas fa-book-open"></i> Read
                        </button>
                        <button class="action-btn secondary" id="update-status">
                            <i class="fas fa-sync-alt"></i> Update Status
                        </button>
                        <button class="action-btn danger" id="remove-book">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
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
        // Book data storage
        const booksData = {};
        
        <?php foreach ($user_books as $book): ?>
            booksData[<?php echo e($book['book_id']); ?>] = {
                title: '<?php echo addslashes($book['title']); ?>',
                author: '<?php echo addslashes($book['author']); ?>',
                description: '<?php echo addslashes($book['description']); ?>',
                pdf_url: '<?php echo addslashes($book['file_url']); ?>',
                cover_url: '<?php echo addslashes($book['cover_url']); ?>',
                rating: 4.5,
                status: '<?php echo e($book['status']); ?>',
                progress: <?php echo e($book['progress']); ?>,
                book_id: <?php echo e($book['book_id']); ?>
            };
        <?php endforeach; ?>

        // Global variable to track current book in modal (DECLARED ONCE)
        let currentBookId = null;

        // Modal elements
        const modal = document.getElementById('book-modal');
        const closeModalBtn = document.getElementById('close-modal');
        const readPdfBtn = document.getElementById('read-pdf');
        const removeBookBtn = document.getElementById('remove-book');
        const progressSlider = document.getElementById('progress-slider');
        const progressValue = document.getElementById('progress-value');
        
        // Open modal function
        function openBookModal(bookId) {
            currentBookId = bookId;
            const book = booksData[bookId];
            
            if (book) {
                document.getElementById('modal-cover').src = book.cover_url;
                document.getElementById('modal-title').textContent = book.title;
                document.getElementById('modal-author').textContent = `by ${book.author}`;
                document.getElementById('modal-description').textContent = book.description;
                document.querySelector('.rating-value').textContent = book.rating;
                
                // Update status
                const statusElement = document.getElementById('modal-status');
                let statusText = '';
                switch(book.status) {
                    case 'reading':
                        statusText = 'Currently Reading';
                        statusElement.className = 'status-reading';
                        break;
                    case 'completed':
                        statusText = 'Completed';
                        statusElement.className = 'status-completed';
                        break;
                    case 'want_to_read':
                        statusText = 'Want to Read';
                        statusElement.className = 'status-want_to_read';
                        break;
                    default:
                        statusText = book.status;
                }
                statusElement.textContent = statusText;
                
                // Update progress
                if (progressSlider) {
                    progressSlider.value = book.progress;
                    progressValue.textContent = book.progress + '%';
                }
                
                // Set PDF button
                readPdfBtn.onclick = function() {
                    window.open(book.pdf_url, '_blank');
                };
                
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
        
        // Open modal when clicking on book cards or view details buttons
        document.querySelectorAll('.book-card, .view-details').forEach(element => {
            element.addEventListener('click', (e) => {
                if (e.target.closest('.book-action') && !e.target.closest('.view-details')) {
                    return;
                }
                
                const bookId = e.target.closest('.book-card').getAttribute('data-book-id');
                openBookModal(bookId);
            });
        });
        
        // Progress slider update
        if (progressSlider) {
            progressSlider.addEventListener('input', function() {
                progressValue.textContent = this.value + '%';
            });
            
            progressSlider.addEventListener('change', function() {
                if (currentBookId) {
                    updateProgress(currentBookId, this.value);
                }
            });
        }
        
        // Remove book button
        if (removeBookBtn) {
            removeBookBtn.addEventListener('click', function() {
                if (currentBookId && confirm('Are you sure you want to remove this book from your library?')) {
                    removeFromLibrary(currentBookId);
                }
            });
        }
        
        // Close modal
        closeModalBtn.addEventListener('click', () => {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        });
        
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        // Filter functionality
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                filterBooks(filter);
            });
        });
        
        function filterBooks(filter) {
            const books = document.querySelectorAll('.book-card');
            books.forEach(book => {
                if (filter === 'all' || filter === '') {
                    book.style.display = 'flex';
                } else {
                    const status = book.dataset.status;
                    book.style.display = status === filter ? 'flex' : 'none';
                }
            });
        }
        
        // Sort functionality
        const sortSelect = document.getElementById('sort-books');
        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                const sortBy = this.value;
                sortBooks(sortBy);
            });
        }
        
        function sortBooks(sortBy) {
            const grid = document.getElementById('books-grid');
            const books = Array.from(document.querySelectorAll('.book-card'));
            
            books.sort((a, b) => {
                switch(sortBy) {
                    case 'recent':
                        return new Date(b.dataset.date || 0) - new Date(a.dataset.date || 0);
                    case 'title':
                        return (a.dataset.title || '').localeCompare(b.dataset.title || '');
                    case 'author':
                        return (a.dataset.author || '').localeCompare(b.dataset.author || '');
                    case 'progress':
                        return (parseInt(b.dataset.progress) || 0) - (parseInt(a.dataset.progress) || 0);
                    default:
                        return 0;
                }
            });
            
            books.forEach(book => grid.appendChild(book));
        }
        
        // Search functionality
        const searchInput = document.querySelector('.search-bar input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const books = document.querySelectorAll('.book-card');
                
                books.forEach(book => {
                    const title = (book.dataset.title || '').toLowerCase();
                    const author = (book.dataset.author || '').toLowerCase();
                    
                    if (title.includes(searchTerm) || author.includes(searchTerm)) {
                        book.style.display = 'flex';
                    } else {
                        book.style.display = 'none';
                    }
                });
            });
        }
        
        // AJAX Functions
        function updateProgress(bookId, progress) {
            fetch('update_progress.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `book_id=${bookId}&progress=${progress}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update book card progress
                    const bookCard = document.querySelector(`.book-card[data-book-id="${bookId}"]`);
                    if (bookCard) {
                        const progressFill = bookCard.querySelector('.progress-fill');
                        if (progressFill) {
                            progressFill.style.width = progress + '%';
                        }
                        bookCard.dataset.progress = progress;
                    }
                    
                    // Update booksData
                    if (booksData[bookId]) {
                        booksData[bookId].progress = progress;
                    }
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        // Update status function (called by the button)
        function updateBookStatus(bookId) {
            const statuses = ['reading', 'completed', 'want_to_read'];
            const currentStatus = booksData[bookId].status;
            const currentIndex = statuses.indexOf(currentStatus);
            const nextStatus = statuses[(currentIndex + 1) % statuses.length];
            
            let statusText = '';
            switch(nextStatus) {
                case 'reading':
                    statusText = 'Currently Reading';
                    break;
                case 'completed':
                    statusText = 'Completed';
                    break;
                case 'want_to_read':
                    statusText = 'Want to Read';
                    break;
            }
            
            fetch('update_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `book_id=${bookId}&status=${nextStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update book card
                    const bookCard = document.querySelector(`.book-card[data-book-id="${bookId}"]`);
                    if (bookCard) {
                        bookCard.dataset.status = nextStatus;
                        const statusSpan = bookCard.querySelector('.book-status');
                        if (statusSpan) {
                            statusSpan.className = `book-status status-${nextStatus}`;
                            statusSpan.textContent = statusText.replace('Currently ', '');
                        }
                    }
                    
                    // Update modal if open
                    if (currentBookId == bookId) {
                        const modalStatus = document.getElementById('modal-status');
                        if (modalStatus) {
                            modalStatus.className = `status-${nextStatus}`;
                            modalStatus.textContent = statusText;
                        }
                    }
                    
                    // Update booksData
                    booksData[bookId].status = nextStatus;
                    
                    alert(`Status updated to: ${statusText}`);
                } else {
                    alert('Error: ' + (data.message || 'Failed to update status'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to connect to server');
            });
        }
        
        // Set up the Update Status button
        document.addEventListener('DOMContentLoaded', function() {
            const updateStatusBtn = document.getElementById('update-status');
            if (updateStatusBtn) {
                updateStatusBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (!currentBookId) {
                        alert('No book selected');
                        return;
                    }
                    
                    if (!booksData[currentBookId]) {
                        alert('Book data not found');
                        return;
                    }
                    
                    updateBookStatus(currentBookId);
                });
            }
        });
        
        function removeFromLibrary(bookId, event) {
            if (event) {
                event.stopPropagation();
            }
            
            if (!confirm('Are you sure you want to remove this book from your library?')) {
                return;
            }
            
            fetch('remove_from_library.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `book_id=${bookId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const bookCard = document.querySelector(`.book-card[data-book-id="${bookId}"]`);
                    if (bookCard) {
                        bookCard.remove();
                        
                        // Check if grid is empty
                        const grid = document.getElementById('books-grid');
                        if (grid.children.length === 0) {
                            location.reload(); // Reload to show empty state
                        }
                    }
                    
                    // Close modal if open
                    if (modal.classList.contains('active')) {
                        modal.classList.remove('active');
                        document.body.style.overflow = 'auto';
                    }
                }
            })
            .catch(error => console.error('Error:', error));
        }

        // Handle bookmark and other buttons
        document.querySelectorAll('.book-action:not(.view-details):not(.delete)').forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const icon = this.querySelector('i');
                if (!icon) return;
                
                const action = icon.className;
                const bookCard = this.closest('.book-card');
                if (!bookCard) return;
                
                const bookTitle = bookCard.querySelector('.book-title')?.textContent || 'Book';
                
                if (action.includes('fa-pen') || action.includes('fa-edit')) {
                    const bookId = bookCard.dataset.bookId;
                    if (bookId) openBookModal(bookId);
                } else if (action.includes('fa-share-alt')) {
                    alert(`Sharing "${bookTitle}"`);
                }
            });
        });
    </script>
</body>
</html>