<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.html');
    exit;
}
require_once __DIR__ . '/db_connect.php';
$uid = (int)$_SESSION['user_id'];

// Get user data
$stmt = $conn->prepare('SELECT user_id, name, email, role, subscription_type, avatar_url, bio, created_at FROM users WHERE user_id = ? LIMIT 1');
$stmt->bind_param('i', $uid);
$stmt->execute();
$res = $stmt->get_result();
$user = $res->fetch_assoc();
if (!$user) {
    echo "User not found";
    exit;
}
if (empty($user['avatar_url'])) $user['avatar_url'] = 'https://i.pravatar.cc/150?img=32';

// Get reading stats from user_library
$stats = [];

// Total books in library
$total_library = $conn->query("SELECT COUNT(*) as count FROM user_library WHERE user_id = $uid")->fetch_assoc()['count'];

// Books by status
$reading = $conn->query("SELECT COUNT(*) as count FROM user_library WHERE user_id = $uid AND status = 'reading'")->fetch_assoc()['count'];
$completed = $conn->query("SELECT COUNT(*) as count FROM user_library WHERE user_id = $uid AND status = 'completed'")->fetch_assoc()['count'];
$wishlist = $conn->query("SELECT COUNT(*) as count FROM user_library WHERE user_id = $uid AND status = 'wishlist'")->fetch_assoc()['count'];
$purchased = $conn->query("SELECT COUNT(*) as count FROM user_library WHERE user_id = $uid AND status = 'purchased'")->fetch_assoc()['count'];

// Get currently reading books with progress
$currently_reading = [];
$reading_query = "SELECT ul.*, b.title, b.author, b.cover_url 
                  FROM user_library ul 
                  JOIN books b ON ul.book_id = b.book_id 
                  WHERE ul.user_id = $uid AND ul.status = 'reading' 
                  ORDER BY ul.last_opened DESC 
                  LIMIT 5";
$reading_res = $conn->query($reading_query);
if ($reading_res) {
    while ($row = $reading_res->fetch_assoc()) {
        $currently_reading[] = $row;
    }
}

// Get user's library books (for bookshelf)
$library_books = [];
$library_query = "SELECT ul.*, b.title, b.author, b.cover_url 
                  FROM user_library ul 
                  JOIN books b ON ul.book_id = b.book_id 
                  WHERE ul.user_id = $uid 
                  ORDER BY ul.last_opened DESC 
                  LIMIT 12";
$library_res = $conn->query($library_query);
if ($library_res) {
    while ($row = $library_res->fetch_assoc()) {
        $library_books[] = $row;
    }
}

// Get user's reviews count
$reviews_count = $conn->query("SELECT COUNT(*) as count FROM reviews WHERE user_id = $uid")->fetch_assoc()['count'];

// Get average rating from user's reviews
$avg_rating = $conn->query("SELECT AVG(rating) as avg FROM reviews WHERE user_id = $uid")->fetch_assoc()['avg'];
$avg_rating = $avg_rating ? number_format($avg_rating, 1) : '0.0';

// Get total reading time (estimated - 30min per 10% progress)
$total_progress = $conn->query("SELECT SUM(progress) as total FROM user_library WHERE user_id = $uid")->fetch_assoc()['total'];
$reading_hours = $total_progress ? round(($total_progress / 10) * 0.5) : 0;

// Get member since date
$member_since = date('F j, Y', strtotime($user['created_at']));

function e($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); 
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booksmart - Profile</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --primary: #4361ee;
  --primary-dark: #3a56d4;
  --secondary: #7209b7;
  --light: #f8f9fa;
  --dark: #212529;
  --gray: #6c757d;
  --success: #4cc9f0;
  --warning: #ff9e00;
  --border-radius: 12px;
  --box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
  --transition: all 0.3s ease;
}

body { 
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; 
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    margin: 0; 
    padding-bottom: 40px;
}

/* HEADER - Matching the catalog page */
header { 
    background: linear-gradient(to right, var(--primary), var(--secondary)); 
    color: white; 
    padding: 15px 30px; 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    height: 80px; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.15); 
    position: sticky; 
    top: 0; 
    z-index: 100; 
}
header .logo { 
    display: flex;
    align-items: center;
    color: white;
    font-weight: 700;
    font-size: 28px;
    text-decoration: none;
}
header .logo i {
    margin-right: 10px;
    font-size: 32px;
}
header .logo:hover { 
    transform: scale(1.05); 
    cursor: pointer;
}
header .search-bar { 
    flex: 1; 
    margin: 0 20px; 
    display: flex; 
    align-items: center; 
    max-width: 500px;
}
header .search-bar input { 
    width: 100%; 
    padding: 12px 20px; 
    border-radius: 25px; 
    border: none; 
    font-size: 1em; 
    outline: none; 
    transition: all 0.3s;
    box-shadow: inset 0 2px 5px rgba(0,0,0,0.1);
}
header .search-bar input:focus { 
    box-shadow: 0 0 15px rgba(255,255,255,0.3), inset 0 2px 5px rgba(0,0,0,0.1);
    transform: scale(1.02);
}

/* Profile dropdown */
.profile { 
    position: relative; 
    display: inline-block; 
}
.profile img { 
    width: 45px; 
    height: 45px; 
    border-radius: 50%; 
    cursor: pointer; 
    transition: all 0.3s;
    border: 2px solid rgba(255,255,255,0.3);
    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.profile img:hover { 
    transform: scale(1.1); 
    border-color: white;
}
.profile .dropdown { 
    display: none; 
    position: absolute; 
    right: 0; 
    top: 60px; 
    background: white; 
    color: black; 
    min-width: 180px; 
    padding: 15px; 
    border-radius: var(--border-radius); 
    box-shadow: var(--box-shadow); 
    z-index: 1000;
}
.profile.active .dropdown { 
    display: block; 
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.dropdown img { 
    width: 80px; 
    height: 80px; 
    border-radius: 50%; 
    display: block; 
    margin: 0 auto 15px auto; 
    border: 3px solid var(--primary);
}
.dropdown a { 
    text-decoration: none; 
    color: var(--dark); 
    display: block; 
    padding: 10px 15px; 
    font-weight: 500; 
    border-radius: 6px;
    transition: all 0.2s;
}
.dropdown a:hover { 
    background: #f0f7ff; 
    color: var(--primary);
    transform: translateX(5px);
}

/* Profile Container */
.profile-container {
    max-width: 1200px;
    margin: 40px auto;
    padding: 0 20px;
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 30px;
}

/* Profile Sidebar */
.profile-sidebar {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    padding: 30px;
    text-align: center;
    height: fit-content;
    position: sticky;
    top: 100px;
}

.profile-picture {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    object-fit: cover;
    border: 5px solid var(--primary);
    margin: 0 auto 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.profile-name {
    font-size: 24px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 5px;
}

.profile-username {
    color: var(--gray);
    margin-bottom: 10px;
    font-size: 14px;
}

.member-since {
    color: var(--gray);
    font-size: 13px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
}

.member-since i {
    color: var(--primary);
}

.profile-stats {
    display: flex;
    justify-content: space-around;
    margin: 25px 0;
    padding: 15px 0;
    border-top: 1px solid #f0f2f5;
    border-bottom: 1px solid #f0f2f5;
}

.stat {
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--primary);
}

.stat-label {
    font-size: 14px;
    color: var(--gray);
}

.profile-bio {
    color: var(--dark);
    line-height: 1.6;
    margin-bottom: 20px;
    padding: 0 10px;
    font-style: italic;
}

.edit-profile-btn {
    display: block;
    width: 100%;
    padding: 12px;
    background: linear-gradient(to right, var(--primary), var(--secondary));
    color: white;
    border: none;
    border-radius: var(--border-radius);
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    margin-top: 20px;
}

.edit-profile-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
}

/* Profile Content */
.profile-content {
    display: flex;
    flex-direction: column;
    gap: 30px;
}

.profile-section {
    background: white;
    border-radius: var(--border-radius);
    box-shadow: var(--box-shadow);
    padding: 30px;
}

.section-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--dark);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f2f5;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: var(--primary);
}

/* Currently Reading */
.currently-reading {
    display: flex;
    gap: 20px;
    overflow-x: auto;
    padding: 10px 0;
    scrollbar-width: thin;
    scrollbar-color: var(--primary) #f0f2f5;
}

.currently-reading::-webkit-scrollbar {
    height: 6px;
}

.currently-reading::-webkit-scrollbar-track {
    background: #f0f2f5;
    border-radius: 10px;
}

.currently-reading::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 10px;
}

.reading-book {
    flex: 0 0 auto;
    width: 150px;
    text-align: center;
}

.reading-book img {
    width: 100%;
    height: 200px;
    border-radius: var(--border-radius);
    object-fit: cover;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transition: var(--transition);
}

.reading-book img:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.15);
}

.reading-book h4 {
    margin: 10px 0 5px;
    font-size: 16px;
    font-weight: 600;
    color: var(--dark);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.reading-book p {
    margin: 0;
    color: var(--gray);
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.progress-bar {
    height: 6px;
    background: #f0f2f5;
    border-radius: 3px;
    margin: 10px 0 5px;
    overflow: hidden;
}

.progress {
    height: 100%;
    background: linear-gradient(to right, var(--primary), var(--secondary));
    border-radius: 3px;
}

.progress-text {
    font-size: 12px;
    color: var(--gray);
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: var(--gray);
    width: 100%;
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 15px;
    color: #ddd;
}

.empty-state p {
    font-size: 16px;
}

/* Reading Stats */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.stat-card {
    background: #f8f9fa;
    border-radius: var(--border-radius);
    padding: 20px;
    text-align: center;
    transition: var(--transition);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.stat-card i {
    font-size: 32px;
    color: var(--primary);
    margin-bottom: 10px;
}

.stat-card h3 {
    font-size: 28px;
    font-weight: 700;
    color: var(--dark);
    margin: 10px 0 5px;
}

.stat-card p {
    color: var(--gray);
    margin: 0;
}

/* Bookshelf */
.bookshelf {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.book-item {
    text-align: center;
    transition: var(--transition);
    cursor: pointer;
}

.book-item:hover {
    transform: translateY(-5px);
}

.book-item img {
    width: 100%;
    height: 200px;
    border-radius: var(--border-radius);
    object-fit: cover;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.book-item h4 {
    margin: 10px 0 5px;
    font-size: 16px;
    font-weight: 600;
    color: var(--dark);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.book-item p {
    margin: 0;
    color: var(--gray);
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.book-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 5px;
}

.badge-reading {
    background: rgba(76, 201, 240, 0.2);
    color: var(--success);
}

.badge-completed {
    background: rgba(76, 201, 240, 0.2);
    color: #2ecc71;
}

.badge-wishlist {
    background: rgba(255, 158, 0, 0.2);
    color: var(--warning);
}

.badge-purchased {
    background: rgba(67, 97, 238, 0.1);
    color: var(--primary);
}

/* Footer */
footer {
    background: linear-gradient(to right, var(--primary), var(--secondary));
    color: white;
    text-align: center;
    padding: 20px;
    margin-top: 40px;
}

/* Edit Profile Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: var(--border-radius);
    padding: 30px;
    width: 90%;
    max-width: 500px;
    box-shadow: var(--box-shadow);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--dark);
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--gray);
}

.close-btn:hover {
    color: var(--dark);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--dark);
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: var(--border-radius);
    font-size: 16px;
    transition: var(--transition);
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.submit-btn {
    background: linear-gradient(to right, var(--primary), var(--secondary));
    color: white;
    border: none;
    border-radius: var(--border-radius);
    padding: 12px 24px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    width: 100%;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
}

/* Responsive Design */
@media (max-width: 992px) {
    .profile-container {
        grid-template-columns: 1fr;
    }
    
    .profile-sidebar {
        position: static;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    header {
        padding: 15px 20px;
        height: 70px;
    }
    
    header .logo {
        font-size: 24px;
    }
    
    header .logo i {
        font-size: 28px;
    }
    
    .profile-container {
        padding: 0 15px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .bookshelf {
        grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    }
}

@media (max-width: 576px) {
    .profile-sidebar, .profile-section {
        padding: 20px;
    }
    
    .profile-picture {
        width: 120px;
        height: 120px;
    }
}
</style>
</head>
<body>

<header>
    <a href="home.php" class="logo">
        <i class="fas fa-book"></i>
        Booksmart
    </a>
    <div class="search-bar"><input type="text" placeholder="Pretraži knjige..."></div>
    <div class="profile" id="profileBox">
        <img id="headerAvatar" src="<?php echo e($user['avatar_url']); ?>" alt="Profile">
        <div class="dropdown">
            <img id="dropdownAvatar" src="<?php echo e($user['avatar_url']); ?>" alt="Account Image">
            <a href="profpage.php">Profil</a>
            <a href="mybooks.php">Moja biblioteka</a>
            <a href="reviews.php?user_id=<?php echo $uid; ?>">Moje recenzije</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
</header>

<div class="profile-container">
    <!-- Profile Sidebar -->
    <div class="profile-sidebar">
        <img id="profilePicture" src="<?php echo e($user['avatar_url']); ?>" alt="Profile Picture" class="profile-picture">
        <h2 id="profileName" class="profile-name"><?php echo e($user['name']); ?></h2>
        <p id="profileUsername" class="profile-username">@<?php echo explode('@', $user['email'])[0]; ?></p>
        
        <div class="member-since">
            <i class="far fa-calendar-alt"></i>
            <span>Član od <?php echo date('F Y', strtotime($user['created_at'])); ?></span>
        </div>
        
        <div class="profile-stats">
            <div class="stat">
                <div class="stat-value"><?php echo $completed; ?></div>
                <div class="stat-label">Pročitano</div>
            </div>
            <div class="stat">
                <div class="stat-value"><?php echo $reading; ?></div>
                <div class="stat-label">Trenutno</div>
            </div>
            <div class="stat">
                <div class="stat-value"><?php echo $wishlist; ?></div>
                <div class="stat-label">Želje</div>
            </div>
        </div>
        
        <p id="profileBio" class="profile-bio"><?php echo e($user['bio'] ?? 'Meni je Ramo Isak preporučio ovu stranicu.'); ?></p>
        
        <button class="edit-profile-btn" id="openEditProfileBtn">Uredi profil</button>
    </div>
    
    <!-- Profile Content -->
    <div class="profile-content">
        <!-- Currently Reading Section -->
        <div class="profile-section">
            <h2 class="section-title"><i class="fas fa-book-open"></i> Trenutno čitam</h2>
            <div class="currently-reading">
                <?php if (empty($currently_reading)): ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>Trenutno ne čitaš nijednu knjigu.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($currently_reading as $book): ?>
                        <div class="reading-book" onclick="window.location.href='catalog.php?book_id=<?php echo $book['book_id']; ?>'">
                            <img src="<?php echo e($book['cover_url'] ?: 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($book['title']); ?>">
                            <h4><?php echo e(strlen($book['title']) > 25 ? substr($book['title'], 0, 25) . '...' : $book['title']); ?></h4>
                            <p><?php echo e($book['author']); ?></p>
                            <div class="progress-bar">
                                <div class="progress" style="width: <?php echo $book['progress']; ?>%"></div>
                            </div>
                            <span class="progress-text"><?php echo $book['progress']; ?>% pročitano</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Reading Stats Section -->
        <div class="profile-section">
            <h2 class="section-title"><i class="fas fa-chart-bar"></i> Statistika čitanja</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-book-open"></i>
                    <h3><?php echo $completed; ?></h3>
                    <p>Pročitane knjige</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock"></i>
                    <h3><?php echo $reading_hours; ?></h3>
                    <p>Sati čitanja</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star"></i>
                    <h3><?php echo $avg_rating; ?></h3>
                    <p>Prosječna ocjena</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-pen"></i>
                    <h3><?php echo $reviews_count; ?></h3>
                    <p>Recenzije</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-bookmark"></i>
                    <h3><?php echo $total_library; ?></h3>
                    <p>U biblioteci</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-heart"></i>
                    <h3><?php echo $wishlist; ?></h3>
                    <p>Na listi želja</p>
                </div>
            </div>
        </div>
        
        <!-- Bookshelf Section -->
        <div class="profile-section">
            <h2 class="section-title"><i class="fas fa-books"></i> Moja biblioteka</h2>
            <div class="bookshelf">
                <?php if (empty($library_books)): ?>
                    <div class="empty-state" style="grid-column: 1/-1;">
                        <i class="fas fa-book"></i>
                        <p>Još nemaš knjiga u biblioteci.</p>
                        <a href="catalog.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Pretraži katalog →</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($library_books as $book): ?>
                        <div class="book-item" onclick="window.location.href='catalog.php?book_id=<?php echo $book['book_id']; ?>'">
                            <img src="<?php echo e($book['cover_url'] ?: 'https://images.unsplash.com/photo-1544947950-fa07a98d237f'); ?>" alt="<?php echo e($book['title']); ?>">
                            <h4><?php echo e(strlen($book['title']) > 25 ? substr($book['title'], 0, 25) . '...' : $book['title']); ?></h4>
                            <p><?php echo e($book['author']); ?></p>
                            <?php
                            $badge_class = '';
                            $badge_text = '';
                            switch($book['status']) {
                                case 'reading':
                                    $badge_class = 'badge-reading';
                                    $badge_text = 'Čitam';
                                    break;
                                case 'completed':
                                    $badge_class = 'badge-completed';
                                    $badge_text = 'Pročitano';
                                    break;
                                case 'wishlist':
                                    $badge_class = 'badge-wishlist';
                                    $badge_text = 'Želim';
                                    break;
                                default:
                                    $badge_class = 'badge-purchased';
                                    $badge_text = 'U vlasništvu';
                            }
                            ?>
                            <span class="book-badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if (count($library_books) >= 12): ?>
                <div style="text-align: center; margin-top: 20px;">
                    <a href="mybooks.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Pogledaj svih <?php echo $total_library; ?> knjiga →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Edit Profile Modal -->
<div id="editProfileModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Uredi profil</h3>
            <button class="close-btn">&times;</button>
        </div>
        <form id="editProfileForm" action="profile_update.php" method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="avatar">Profilna slika</label>
                <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif">
                <small style="color: var(--gray); display: block; margin-top: 5px;">Ostavite prazno ako ne želite mijenjati sliku.</small>
            </div>
            <div class="form-group">
                <label for="bio">Biografija</label>
                <textarea id="bio" name="bio" placeholder="O sebi..."><?php echo e($user['bio'] ?? ''); ?></textarea>
            </div>
            <button type="submit" class="submit-btn">Spremi promjene</button>
        </form>
    </div>
</div>

<footer>
    <p>&copy; 2023 Booksmart. Sva prava zadržana.</p>
</footer>

<script>
// Profile dropdown
const profile = document.getElementById('profileBox');
document.addEventListener('click', function(e){
    if(profile.contains(e.target)) profile.classList.toggle('active');
    else profile.classList.remove('active');
});

// Edit profile modal
const editProfileBtn = document.getElementById('openEditProfileBtn');
const editProfileModal = document.getElementById('editProfileModal');
const closeBtn = document.querySelector('.close-btn');

editProfileBtn.addEventListener('click', function() {
    editProfileModal.style.display = 'flex';
});

closeBtn.addEventListener('click', function() {
    editProfileModal.style.display = 'none';
});

window.addEventListener('click', function(e) {
    if (e.target === editProfileModal) {
        editProfileModal.style.display = 'none';
    }
});

// Handle form submission with AJAX for better UX
document.getElementById('editProfileForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('profile_update.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data && data.success) {
            const avatar = data.avatar_url;
            if (avatar) {
                const header = document.getElementById('headerAvatar');
                const dropdown = document.getElementById('dropdownAvatar');
                const profilePic = document.getElementById('profilePicture');
                if (header) header.src = avatar;
                if (dropdown) dropdown.src = avatar;
                if (profilePic) profilePic.src = avatar;
            }
            // update bio if sent
            const bioField = document.getElementById('profileBio');
            const newBio = document.getElementById('bio') ? document.getElementById('bio').value : null;
            if (bioField && newBio !== null) bioField.textContent = newBio;
            editProfileModal.style.display = 'none';
            alert('Profil uspješno ažuriran!');
        } else {
            alert('Došlo je do greške prilikom ažuriranja profila.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Došlo je do greške prilikom ažuriranja profila.');
    });
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