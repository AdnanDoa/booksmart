<?php
// catalog.php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}

// Database connection - FIXED: Capture the returned connection
$conn = require_once 'db_connect.php';

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
// If avatar_url is a relative path, make it absolute (but only for non-default)
if ($avatar_url && $avatar_url !== 'https://i.pravatar.cc/150?img=32' && !preg_match('/^https?:\/\//', $avatar_url)) {
    $avatar_url = '/' . ltrim($avatar_url, '/');
}

// Get user data
$user_name = $_SESSION['user_name'] ?? 'User';

// DEBUG: Check if books table has data
$checkBooks = $conn->query("SELECT COUNT(*) as count FROM books");
$bookCount = $checkBooks->fetch_assoc()['count'];
$checkBooks->close();

echo "<!-- DEBUG: Books in database: $bookCount -->";

// Import books if database is empty or it's been a while
$lastSync = $_SESSION['last_book_sync'] ?? 0;
if ($bookCount == 0 || time() - $lastSync > 3600) {
    echo "<!-- DEBUG: Starting book import -->";
    
    $apiUrl = "https://gutendex.com/books/?languages=en&mime_type=application/pdf&page=1";
    $json = file_get_contents($apiUrl);
    
    if ($json !== false) {
        $data = json_decode($json, true);
        
        if (isset($data['results'])) {
            $imported = 0;
            foreach ($data['results'] as $book) {
                $title = $conn->real_escape_string($book['title'] ?? 'Untitled');
                $author = $conn->real_escape_string(!empty($book['authors']) ? $book['authors'][0]['name'] : 'Unknown Author');
                $description = $conn->real_escape_string(!empty($book['subjects']) ? implode(', ', array_slice($book['subjects'], 0, 4)) : 'Classic literature');
                
                // Find PDF URL
                $pdfUrl = '';
                foreach ($book['formats'] as $mime => $url) {
                    if (stripos($mime, 'pdf') !== false && $url) {
                        $pdfUrl = $conn->real_escape_string($url);
                        break;
                    }
                }
                
                if (empty($pdfUrl)) continue;
                
                // Find cover image
                $coverUrl = '';
                foreach ($book['formats'] as $mime => $url) {
                    if (stripos($mime, 'image') !== false && $url) {
                        $coverUrl = $conn->real_escape_string($url);
                        break;
                    }
                }
                if (empty($coverUrl)) {
                    $coverUrl = $conn->real_escape_string("https://covers.openlibrary.org/b/title/" . rawurlencode($title) . "-L.jpg");
                }
                
                // Check if book already exists
                $check = $conn->query("SELECT book_id FROM books WHERE title = '$title' AND author = '$author'");
                if ($check->num_rows === 0) {
                    // Insert book
                    $conn->query("INSERT INTO books (title, author, description, cover_url, file_type, is_public_domain, created_at) 
                                 VALUES ('$title', '$author', '$description', '$coverUrl', 'pdf', TRUE, NOW())");
                    $bookId = $conn->insert_id;
                    
                    // Insert PDF file
                    $conn->query("INSERT INTO book_files (book_id, file_type, file_url) 
                                 VALUES ($bookId, 'pdf', '$pdfUrl')");
                    
                    $imported++;
                    echo "<!-- DEBUG: Imported: $title -->";
                }
                $check->close();
            }
            echo "<!-- DEBUG: Imported $imported books -->";
        }
    } else {
        echo "<!-- DEBUG: Failed to fetch from API -->";
    }
    
    $_SESSION['last_book_sync'] = time();
}

// Get books from database (simple query, no pagination)
$books = [];
$result = $conn->query("SELECT b.book_id, b.title, b.author, b.description, b.cover_url, bf.file_url 
                       FROM books b 
                       JOIN book_files bf ON b.book_id = bf.book_id 
                       WHERE bf.file_type = 'pdf'
                       ORDER BY b.book_id DESC 
                       LIMIT 12");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    $result->close();
}

echo "<!-- DEBUG: Found " . count($books) . " books to display -->";

function e($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booksmart - Catalog</title>
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
            padding: 40px 5%;
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
            margin-bottom: 30px;
            opacity: 0.9;
            max-width: 600px;
        }

        /* Catalog Controls */
        .catalog-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 5% 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .filter-sort {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .filter-btn, .sort-select {
            padding: 12px 20px;
            background: white;
            border: 1px solid var(--light-gray);
            border-radius: var(--border-radius);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-btn:hover, .sort-select:hover {
            border-color: var(--primary);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.1);
        }

        .view-toggle {
            display: flex;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            border: 1px solid var(--light-gray);
        }

        .view-toggle button {
            padding: 12px 20px;
            background: transparent;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .view-toggle button.active {
            background: var(--primary);
            color: white;
        }

        .view-toggle button:hover:not(.active) {
            background: var(--light-gray);
        }

        /* Books Grid */
        .section {
            padding: 0 5% 60px;
        }

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

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 50px;
        }

        .pagination button {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--light-gray);
            background: white;
            cursor: pointer;
            transition: var(--transition);
        }

        .pagination button:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .pagination button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        /* Sexy Modal Styles */
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

        .cover-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, transparent 0%, rgba(0,0,0,0.1) 100%);
            border-radius: var(--border-radius);
            opacity: 0;
            transition: var(--transition);
        }

        .cover-container:hover .cover-overlay {
            opacity: 1;
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
            cursor: pointer;
        }

        .status-available {
            background: rgba(76, 201, 240, 0.2);
            color: var(--success);
        }

        .status-borrowed {
            background: rgba(247, 37, 133, 0.2);
            color: var(--accent);
        }

        .status-reserved {
            background: rgba(255, 158, 0, 0.2);
            color: var(--warning);
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

        .action-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }

        .action-btn.primary:hover {
            background: var(--primary-dark);
        }

        .action-btn.secondary:hover {
            background: rgba(67, 97, 238, 0.1);
        }

        /* Floating animation for modal */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        .modal-content {
            animation: float 6s ease-in-out infinite;
        }

        /* Responsive adjustments */
        @media (max-width: 1100px) {
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
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
            
            .page-title {
                font-size: 2.5rem;
            }
            
            .catalog-controls {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .modal-body {
                flex-direction: column;
            }
            
            .modal-cover-section {
                padding: 30px 20px;
            }
            
            .modal-details-section {
                padding: 30px 20px;
            }
            
            #modal-title {
                font-size: 1.8rem;
            }
            
            .modal-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 2rem;
            }
            
            .books-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 20px;
            }
            
            .book-cover {
                height: 240px;
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
            <a href="#" class="active">Catalog</a>
            <a href="#">My Books</a>
            <a href="#">Reviews</a>
            <a href="#">Community</a>
        </nav>
        
        <div class="header-actions">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search books, authors...">
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
            <h1 class="page-title">Book Catalog</h1>
            <p class="page-subtitle">Browse our extensive collection of books from various genres and authors. Find your next favorite read!</p>
        </div>
    </section>

    <!-- Catalog Controls -->
    <div class="catalog-controls">
        <div class="filter-sort">
            <div class="filter-btn">
                <i class="fas fa-filter"></i>
                Filter
            </div>
            <div class="sort-select">
                <i class="fas fa-sort"></i>
                Sort by: Popularity
            </div>
        </div>
        
        <div class="view-toggle">
            <button class="active"><i class="fas fa-th"></i></button>
            <button><i class="fas fa-list"></i></button>
        </div>
    </div>

    <!-- Books Grid -->
    <section class="section">
        <div class="books-grid">
            <?php if (!empty($books)): ?>
                <?php foreach ($books as $book): ?>
                    <div class='book-card' data-book-id='<?php echo e($book['book_id']); ?>'>
                        <div class='book-cover'>
                            <img src='<?php echo e($book['cover_url']); ?>' alt='<?php echo e($book['title']); ?>' onerror="this.src='https://images.unsplash.com/photo-1544947950-fa07a98d237f?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80'">
                            <div class='book-overlay'>
                                <button class='book-action view-details'><i class='fas fa-eye'></i></button>
                                <button class='book-action'><i class='fas fa-bookmark'></i></button>
                                <button class='book-action'><i class='fas fa-share-alt'></i></button>
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
                                <span class='book-status status-available'>Available</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style='grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--gray);'>No books found in the database.</p>
            <?php endif; ?>
        </div>
        
      
    </section>

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
                    <li><a href="#">Catalog</a></li>
                    <li><a href="#">My Books</a></li>
                    <li><a href="#">Reviews</a></li>
                    <li><a href="#">Community</a></li>
                </ul>
            </div>
            
            <div class="footer-column">
                <h3>Account</h3>
                <ul class="footer-links">
                    <li><a href="#">Profile</a></li>
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

    <!-- Sexy Book Details Modal -->
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
                    
                    <span id="modal-status" class="status-available">Available</span>
                    
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
                            <i class="fas fa-book-open"></i> Read PDF
                        </button>
                        <button class="action-btn secondary">
                            <i class="fas fa-bookmark"></i> Add to Library
                        </button>
                        <button class="action-btn secondary">
                            <i class="fas fa-share-alt"></i> Share
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Book data storage
        const booksData = {};
        
        <?php foreach ($books as $book): ?>
            booksData[<?php echo e($book['book_id']); ?>] = {
                title: '<?php echo addslashes($book['title']); ?>',
                author: '<?php echo addslashes($book['author']); ?>',
                description: '<?php echo addslashes($book['description']); ?>',
                pdf_url: '<?php echo addslashes($book['file_url']); ?>',
                cover_url: '<?php echo addslashes($book['cover_url']); ?>',
                rating: 4.5,
                status: 'available'
            };
        <?php endforeach; ?>

        // Modal functionality
        const modal = document.getElementById('book-modal');
        const closeModalBtn = document.getElementById('close-modal');
        const readPdfBtn = document.getElementById('read-pdf');
        
        // Open modal when clicking on book cards or view details buttons
        document.querySelectorAll('.book-card, .view-details').forEach(element => {
            element.addEventListener('click', (e) => {
                // Prevent event bubbling for buttons inside book cards
                if (e.target.closest('.book-action') && !e.target.closest('.view-details')) {
                    return;
                }
                
                const bookId = e.target.closest('.book-card').getAttribute('data-book-id');
                openBookModal(bookId);
            });
        });
        
        function openBookModal(bookId) {
            const book = booksData[bookId];
            
            if (book) {
                // Update modal content
                document.getElementById('modal-cover').src = book.cover_url;
                document.getElementById('modal-title').textContent = book.title;
                document.getElementById('modal-author').textContent = `by ${book.author}`;
                document.getElementById('modal-description').textContent = book.description;
                
                // Update rating
                document.querySelector('.rating-value').textContent = book.rating;
                
                // Update status
                const statusElement = document.getElementById('modal-status');
                statusElement.textContent = book.status.charAt(0).toUpperCase() + book.status.slice(1);
                statusElement.className = '';
                
                if (book.status === 'available') {
                    statusElement.classList.add('status-available');
                } else if (book.status === 'borrowed') {
                    statusElement.classList.add('status-borrowed');
                } else if (book.status === 'reserved') {
                    statusElement.classList.add('status-reserved');
                }
                
                // Set PDF URL for reading
                readPdfBtn.onclick = function() {
                    window.open(book.pdf_url, '_blank');
                };
                
                // Show modal
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }
        
        closeModalBtn.addEventListener('click', () => {
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        });
        
        // Close modal when clicking outside
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });
        
        // Demo: Change book status on click in modal
        const statusElement = document.getElementById('modal-status');
        statusElement.addEventListener('click', () => {
            if (statusElement.classList.contains('status-available')) {
                statusElement.classList.remove('status-available');
                statusElement.classList.add('status-borrowed');
                statusElement.textContent = 'Borrowed';
            } else if (statusElement.classList.contains('status-borrowed')) {
                statusElement.classList.remove('status-borrowed');
                statusElement.classList.add('status-reserved');
                statusElement.textContent = 'Reserved';
            } else {
                statusElement.classList.remove('status-reserved');
                statusElement.classList.add('status-available');
                statusElement.textContent = 'Available';
            }
        });

        // Other functionality from the catalog
        document.querySelectorAll('.view-toggle button').forEach(button => {
            button.addEventListener('click', function() {
                document.querySelectorAll('.view-toggle button').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
        
        document.querySelector('.filter-btn').addEventListener('click', function() {
            alert('Filter options would appear here in a real application');
        });
        
        document.querySelector('.sort-select').addEventListener('click', function() {
            alert('Sort options would appear here in a real application');
        });
        
        // Book action buttons in grid
        document.querySelectorAll('.book-action').forEach(button => {
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                const action = this.querySelector('i').className;
                const bookTitle = this.closest('.book-card').querySelector('.book-title').textContent;
                
                if (action.includes('fa-bookmark')) {
                    alert(`"${bookTitle}" added to your library`);
                } else if (action.includes('fa-share-alt')) {
                    alert(`Sharing "${bookTitle}"`);
                }
            });
        });
    </script>
</body>
</html>