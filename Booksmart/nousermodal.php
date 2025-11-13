<?php 
session_start();

// correct variable name: $isLoggedIn (capital L)
$isLoggedIn = isset($_SESSION['user']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Booksmart</title>

  <style>
    .auth-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.6); /* fixed 0,6 -> 0.6 */
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      animation: fadeIn 0.3s ease;
    }

    .auth-modal-content {
      background: #fff;
      border-radius: 1rem;
      padding: 1.5rem;
      width: 300px;
      text-align: center;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2); /* fixed 0,2 -> 0.2 */
    }

    .auth-modal-content h2 {
      margin-bottom: 0.5rem;
    }

    .auth-modal-content p {
      color: #555;
      margin-bottom: 1.5rem;
    }

    .auth-modal-buttons {
      display: flex;
      justify-content: space-around;
    }

    .auth-modal-buttons a {
      padding: 0.5rem 1rem; /* fixed comma -> space */
      border-radius: 0.5rem;
      text-decoration: none;
      color: white;
      transition: 0.25s; /* fixed missing colon */
    }

    .auth-modal-buttons .register {
      background-color: #2563eb;
    }

    .auth-modal-buttons .register:hover {
      background-color: #1d4ed8;
    }

    .auth-modal-buttons .login {
      background-color: #9ca3af;
    }

    .auth-modal-buttons .login:hover {
      background-color: #6b7280;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>

<body>
  <div id="authModal" class="auth-modal" style="display:none;">
    <div class="auth-modal-content">
      <h2>Welcome!</h2>
      <p>You’re not logged in yet. Please register or log in.</p>
      <div class="auth-modal-buttons">
        <a href="register.php" class="register">Register</a>
        <a href="login.php" class="login">Login</a>
      </div>
    </div>
  </div>

  <script>
    // Make sure this matches your PHP variable name
    const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;

    function showAuthModal() {
      document.getElementById('authModal').style.display = 'flex';
    }

    window.addEventListener('load', () => {
      setTimeout(() => {
        if (!isLoggedIn) {
          showAuthModal();
        }
      }, 3000);
    });

  </script>
</body>
</html>
