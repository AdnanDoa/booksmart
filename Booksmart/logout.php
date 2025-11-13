<?php
// logout.php
// Destroy the current session and redirect the user to the login page.

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

// Unset all session variables
$_SESSION = [];

// If session uses cookies, expire the session cookie
if (ini_get('session.use_cookies')) {
	$params = session_get_cookie_params();
	// Set cookie with past expiration to instruct browser to delete it
	setcookie(
		session_name(),
		'',
		time() - 42000,
		$params['path'] ?? '/',
		$params['domain'] ?? '',
		$params['secure'] ?? false,
		$params['httponly'] ?? false
	);
}

// Finally destroy the session on the server
session_destroy();

// Redirect to login page (idempotent)
header('Location: login.html');
exit;

?>
