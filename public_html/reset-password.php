<?php
// reset-password.php - Set new password from token with CSRF
require_once __DIR__ . '/config/config.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$resetData = null;
$error = '';
$success = false;

if (empty($token)) {
    $error = 'Μη έγκυρος ή κενός σύνδεσμος επαναφοράς.';
} else {
    $resetData = verifyResetToken($token);
    if (!$resetData) {
        $error = 'Ο σύνδεσμος έχει λήξει ή έχει ήδη χρησιμοποιηθεί.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetData) {
    if (!verifyCsrfToken()) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας. Παρακαλώ δοκιμάστε ξανά.';
    } else {
        $pass1 = $_POST['password'] ?? '';
        $pass2 = $_POST['password_confirm'] ?? '';

        if (empty($pass1)) {
            $error = 'Παρακαλώ εισάγετε νέο κωδικό.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Οι δύο κωδικοί δεν ταιριάζουν.';
        } elseif (strlen($pass1) < 6) {
            $error = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
        } else {
            $res = completePasswordReset($token, $pass1);
            if ($res['success']) {
                header("Location: index.php?reset=success");
                exit;
            } else {
                $error = $res['message'];
            }
        }
    }
}

$schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
$isInvitation = ($resetData && $resetData['type'] === 'invitation');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isInvitation ? 'Ορισμός Κωδικού' : 'Αλλαγή Κωδικού' ?> - <?= htmlspecialchars($schoolName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=daylight-20261029-1">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="auth-header">
            <div class="school-badge">🔐</div>
            <h1><?= $isInvitation ? 'Ορισμός Κωδικού Πρόσβασης' : 'Νέος Κωδικός Πρόσβασης' ?></h1>
            <p><?= $resetData ? 'Για τον λογαριασμό: <b>' . htmlspecialchars($resetData['email']) . '</b>' : 'Επαναφορά κωδικού' ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <div style="text-align:center; margin-top:16px;">
                <a href="index.php" class="btn btn-secondary btn-block">&larr; Επιστροφή στη Σύνδεση</a>
            </div>
        <?php elseif ($resetData): ?>
            <form method="POST" action="reset-password.php" class="auth-form">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="form-group">
                    <label for="password">Νέος Κωδικός Πρόσβασης</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Τουλάχιστον 6 χαρακτήρες" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password_confirm">Επιβεβαίωση Νέου Κωδικού</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-control" placeholder="Επανάληψη κωδικού" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">
                    <?= $isInvitation ? 'Ενεργοποίηση Λογαριασμού' : 'Αποθήκευση Νέου Κωδικού' ?>
                </button>
            </form>
            <script>
            if (window.history.replaceState && window.location.search.includes('token=')) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>