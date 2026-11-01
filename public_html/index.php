<?php
// index.php - Login Screen & Router with Rate Limiting & CSRF Protection
require_once __DIR__ . '/config/config.php';

// If already logged in, redirect
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'admin') {
        header("Location: admin/index.php");
        exit;
    } else {
        header("Location: teacher/index.php");
        exit;
    }
}

$error = '';
$infoMessage = '';

if (isset($_GET['timeout'])) {
    $infoMessage = 'Η συνεδρία σας έληξε λόγω αδράνειας (60 λεπτά). Παρακαλώ συνδεθείτε ξανά.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    if (!verifyCsrfToken()) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token). Παρακαλώ δοκιμάστε ξανά.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Παρακαλώ συμπληρώστε το email και τον κωδικό πρόσβασης.';
        } else {
            $result = login($email, $password);
            if ($result['success']) {
                if ($result['role'] === 'admin') {
                    header("Location: admin/index.php");
                } else {
                    header("Location: teacher/index.php");
                }
                exit;
            } else {
                $error = $result['message'];
            }
        }
    }
}

$schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Σύνδεση - <?= htmlspecialchars($schoolName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <meta name="theme-color" content="#4f46e5">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Παρουσιολόγιο">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="manifest" href="manifest.json">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=daylight-20261029-1">
    <script src="assets/js/theme.js?v=20261029-1"></script>
</head>
<body class="auth-body">
    <div style="position:fixed; top:20px; right:20px;">
        <button type="button" class="theme-toggle-btn" onclick="toggleSchoolTheme()" title="Εναλλαγή Θέματος" style="background:var(--bg-card); box-shadow:var(--shadow-md);">
            🌙 <span style="font-size:12px; font-weight:600;">Σκοτεινό</span>
        </button>
    </div>
    <div class="auth-card">
        <div class="auth-header">
            <div class="school-badge">🏫</div>
            <h1><?= htmlspecialchars($schoolName) ?></h1>
            <p>Ηλεκτρονικό Παρουσιολόγιο Τάξεων</p>
        </div>

        <?php if (!empty($_GET['reset']) && $_GET['reset'] === 'success'): ?>
            <div class="alert alert-success">
                Ο κωδικός πρόσβασης άλλαξε επιτυχώς! Μπορείτε τώρα να συνδεθείτε.
            </div>
        <?php endif; ?>

        <?php if ($infoMessage): ?>
            <div class="alert alert-info"><?= htmlspecialchars($infoMessage) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php" class="auth-form">
            <?= csrfField() ?>

            <div class="form-group">
                <label for="email">Email Χρήστη</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="π.χ. teacher@school.gr" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="password" style="margin-bottom:0;">Κωδικός Πρόσβασης</label>
                    <a href="forgot-password.php" style="font-size:12px; color:var(--primary); text-decoration:none;">Ξέχασα τον κωδικό;</a>
                </div>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Είσοδος στην Εφαρμογή</button>
        </form>

        <div style="text-align:center; margin-top:24px; padding-top:16px; border-top:1px solid var(--border); font-size:12.5px; color:var(--text-muted); line-height:1.5;">
            <div>Παρουσιολόγιο &amp; Απουσίες <span style="display:inline-block; background:#e2e8f0; color:#334155; font-size:11px; font-weight:700; padding:2px 7px; border-radius:4px; margin-left:4px;">v<?= htmlspecialchars(APP_VERSION, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div style="margin-top:4px;">Δημιουργός: <strong style="color:var(--text-main);">Κόλλιας Σταύρος</strong> &copy; <?= date('Y') ?></div>
            <div><a href="license.php" style="color:var(--primary);">Άδεια GPLv3 · χωρίς εγγύηση</a></div>
        </div>
    </div>
</body>
</html>
