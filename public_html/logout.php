<?php
require_once __DIR__.'/config/config.php';

// If accessed via GET (e.g. direct URL entry, bookmark, or browser navigation), redirect cleanly
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// If already logged out or session expired, clean up session/cookies and redirect to login
if (empty($_SESSION['user_id'])) {
    logout();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

// Active user session: verify CSRF token to prevent forced-logout attacks
if (!verifyCsrfToken()) {
    http_response_code(403);
    exit('Μη έγκυρο αίτημα ασφαλείας.');
}

logout();
header('Location: ' . BASE_URL . '/index.php');
exit;
