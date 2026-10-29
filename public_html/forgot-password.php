<?php
// forgot-password.php - Request password reset with Rate Limiting & CSRF
require_once __DIR__ . '/config/config.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken()) {
        $error = 'Μη έγκυρο αίτημα ασφαλείας. Παρακαλώ δοκιμάστε ξανά.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $rate = ['allowed'=>allowPasswordResetRequest($email)];

        if (!$rate['allowed']) {
            $message = 'Εάν υπάρχει ενεργός λογαριασμός, θα λάβετε σύνδεσμο επαναφοράς. Περιμένετε πριν δοκιμάσετε ξανά.';
        } elseif (empty($email)) {
            $error = 'Παρακαλώ εισάγετε το email σας.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && $user['status'] === 'active') {
                $token = createPasswordResetToken($user['id'], 'reset');
                sendPasswordResetEmail($user, $token, 'reset');
            }
            // Always show friendly message to prevent email enumeration
            $message = 'Εάν υπάρχει ενεργός λογαριασμός με αυτό το email, έχει σταλεί σύνδεσμος για την επαναφορά του κωδικού σας. Ελέγξτε τα εισερχόμενά σας (και τον φάκελο ανεπιθύμητων/spam).';
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
    <title>Επαναφορά Κωδικού - <?= htmlspecialchars($schoolName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=daylight-20261029-1">
</head>
<body class="auth-body">
    <div class="auth-card">
        <div class="auth-header">
            <div class="school-badge">🔑</div>
            <h1>Επαναφορά Κωδικού</h1>
            <p>Συμπληρώστε το email του λογαριασμού σας</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
            <div style="text-align:center; margin-top:20px;">
                <a href="index.php" class="btn btn-secondary btn-block">&larr; Επιστροφή στη Σύνδεση</a>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="forgot-password.php" class="auth-form">
                <?= csrfField() ?>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="π.χ. teacher@school.gr" required autofocus>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Αποστολή Συνδέσμου Επαναφοράς</button>

                <div style="text-align:center; margin-top:16px;">
                    <a href="index.php" style="font-size:13px; color:var(--text-muted); text-decoration:none;">&larr; Επιστροφή στη Σύνδεση</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>