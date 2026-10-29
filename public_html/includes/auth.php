<?php
// includes/auth.php - Authentication, Session Hardening, Rate Limiting & CSRF

// 1. Detect Real Client IP (SEC-04: Only trust proxy headers when behind an explicitly configured trusted reverse proxy)
function getClientIP() {
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $trustedProxies = defined('TRUSTED_PROXIES') ? (array)TRUSTED_PROXIES : [];

    if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $remoteAddr;
}

// 2. Brute Force Protection: Check Rate Limit
function checkRateLimit($email = null) {
    try {
        $db = getDB();
        $ip = getClientIP();
        $email = trim($email ?? '');
        $timeWindow = 15; // 15 minutes window
        $maxAttempts = 5; // max 5 failed attempts allowed

        // Periodic garbage collection: delete attempts older than 24 hours
        if (mt_rand(1, 25) === 1) {
            $db->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        }

        // Count recent failed attempts from this IP or for this specific email
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM login_attempts 
            WHERE (ip_address = ? OR (email != '' AND email = ?))
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$ip, $email, $timeWindow]);
        $failedCount = (int)$stmt->fetchColumn();

        if ($failedCount >= $maxAttempts) {
            // Find time left until lockout expires
            $stmt = $db->prepare("
                SELECT TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(MIN(attempted_at), INTERVAL ? MINUTE))
                FROM login_attempts 
                WHERE (ip_address = ? OR (email != '' AND email = ?))
                  AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$timeWindow, $ip, $email, $timeWindow]);
            $mins = (int)$stmt->fetchColumn();
            $minutesLeft = max(1, $mins);

            return [
                'allowed' => false,
                'message' => "⚠️ Υπερβήκατε το όριο αποτυχημένων προσπαθειών σύνδεσης (5 προσπάθειες). Για λόγους ασφαλείας, η πρόσβαση έχει κλειδωθεί προσωρινά για {$minutesLeft} λεπτό/ά."
            ];
        }

        return ['allowed' => true, 'remaining' => ($maxAttempts - $failedCount)];
    } catch (Exception $e) {
        // Authentication throttling must not silently disappear on DB errors.
        return ['allowed' => false, 'message' => 'Η σύνδεση δεν είναι προσωρινά διαθέσιμη. Δοκιμάστε αργότερα.'];
    }
}

// 3. Record Login Attempt
function recordLoginAttempt($email, $success) {
    try {
        $db = getDB();
        $ip = getClientIP();
        $email = trim($email);

        if ($success) {
            // Keep the IP failure budget and all other accounts' attempts.
            // Failures expire naturally after the configured time window.
            return;
        } else {
            // Record failed attempt
            $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES (?, ?, NOW())");
            $stmt->execute([$ip, $email]);

            // Add artificial delay (0.4s) to slow down automated bots
            usleep(400000);
        }
    } catch (Exception $e) {
        // Fail silently if table not ready
    }
}

// 4. CSRF Protection
function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    $token = htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

function verifyCsrfToken($token = null) {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($token) || empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// 5. Authentication Status
function isLoggedIn() {
    return getCurrentUser() !== null;
}

function getCurrentUser($refresh = false) {
    if (empty($_SESSION['user_id'])) return null;
    $stmt=getDB()->prepare('SELECT id,name,email,role,status,password_hash,auth_version FROM users WHERE id=?');
    $stmt->execute([$_SESSION['user_id']]);
    $user=$stmt->fetch();
    $expired = !isset($_SESSION['last_activity']) || time() - (int)$_SESSION['last_activity'] > 3600;
    $versionValid = $user && isset($_SESSION['auth_version']) && (int)$_SESSION['auth_version'] === (int)$user['auth_version'];
    $passwordValid = $user && isset($_SESSION['password_fingerprint']) && hash_equals($_SESSION['password_fingerprint'], hash('sha256', $user['password_hash'] ?? ''));
    $generationValid=hash_equals(defined('APP_RESTORE_GENERATION')?APP_RESTORE_GENERATION:'',(string)($_SESSION['restore_generation']??''));
    if (!$user || $user['status'] !== 'active' || $expired || !$versionValid || !$passwordValid || !$generationValid) {
        // Keep a fresh anonymous session so a login/reset form rendered in this
        // same request can persist its CSRF token after invalidating old auth.
        $_SESSION=[];
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        return null;
    }
    $_SESSION['last_activity']=time();
    $_SESSION['user_role']=$user['role'];
    unset($user['password_hash']);

    // Periodic activity ping (at most once every 60s) for online teacher status
    if (!isset($_SESSION['last_db_ping']) || (time() - (int)$_SESSION['last_db_ping']) >= 60) {
        try {
            getDB()->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?')->execute([$user['id']]);
            $_SESSION['last_db_ping'] = time();
        } catch (Throwable $e) {}
    }

    return $user;
}

function requireApiLogin() {
    header('Cache-Control: no-store, private');
    $user=getCurrentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error'=>'Απαιτείται νέα σύνδεση.']);
        exit;
    }
    return $user;
}

function requirePost() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>'Απαιτείται POST.']);
        exit;
    }
}

function requireLogin() {
    header('Cache-Control: no-store, private');
    if (!getCurrentUser()) {
        header('Location: '.BASE_URL.'/index.php');
        exit;
    }
}

function logoutForm($class = 'btn btn-secondary btn-sm') {
    $actionPath = (parse_url(BASE_URL, PHP_URL_PATH) ?: '') . '/logout.php';
    return '<form method="post" action="'.htmlspecialchars($actionPath,ENT_QUOTES,'UTF-8').'" style="display:inline">'.csrfField().'<button type="submit" class="'.htmlspecialchars($class,ENT_QUOTES,'UTF-8').'">Έξοδος</button></form>';
}

// Count every request, including unknown accounts. Store only hashed identifiers.
function allowPasswordResetRequest($email) {
    $db=getDB(); $ip=hash('sha256',getClientIP()); $identifier=hash('sha256',mb_strtolower(trim($email)));
    // The year row is a short database mutex for atomic check-and-record.
    $db->beginTransaction();
    try {
        $db->query('SELECT id FROM academic_years ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
        $stmt=$db->prepare('SELECT COUNT(*) AS n, MAX(requested_at) AS latest FROM reset_requests WHERE (ip_hash=? OR identifier_hash=?) AND requested_at > DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
        $stmt->execute([$ip,$identifier]); $row=$stmt->fetch();
        $cool=$db->prepare('SELECT COUNT(*) FROM reset_requests WHERE identifier_hash=? AND requested_at > DATE_SUB(NOW(),INTERVAL 60 SECOND)');
        $cool->execute([$identifier]);
        $allowed=(int)$row['n']<5 && !(int)$cool->fetchColumn();
        $db->prepare('INSERT INTO reset_requests(ip_hash,identifier_hash) VALUES (?,?)')->execute([$ip,$identifier]);
        $db->exec('DELETE FROM reset_requests WHERE requested_at < DATE_SUB(NOW(),INTERVAL 24 HOUR)');
        $db->commit(); return $allowed;
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); return false; }
}

function isAdmin() {
    $user = getCurrentUser();
    return ($user && $user['role'] === 'admin');
}

function isTeacherAdmin() {
    $user = getCurrentUser();
    return ($user && $user['role'] === 'teacher_admin');
}

function canManageAnyAttendance() {
    return (isAdmin() || isTeacherAdmin());
}

function canJustifyAbsences() {
    return isAdmin();
}

function requireAdmin() {
    requireLogin();
    $user = getCurrentUser();
    if ($user['role'] !== 'admin') {
        header("Location: " . BASE_URL . "/teacher/index.php");
        exit;
    }
}

function requireAttendanceManager() {
    requireLogin();
    $user = getCurrentUser();
    if (!in_array($user['role'], ['admin', 'teacher_admin'])) {
        header("Location: " . BASE_URL . "/teacher/index.php");
        exit;
    }
}

function requireTeacher() {
    requireLogin();
    $user = getCurrentUser();
    if (!in_array($user['role'], ['teacher', 'teacher_admin', 'admin'])) {
        header("Location: " . BASE_URL . "/index.php");
        exit;
    }
}

// 7. Login with Rate Limiting & Session Fixation Protection
function login($email, $password) {
    $email = trim($email);

    // Check rate limit before anything else
    $rateCheck = checkRateLimit($email);
    if (!$rateCheck['allowed']) {
        return ['success' => false, 'message' => $rateCheck['message']];
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // SEC-04: Prevent timing attacks and email enumeration via uniform error message & dummy verification
    $dummyHash = '$2y$10$e0MYzXyjpJS7Pd0RVvHwHeFXZz6F6r6/0uY3y3t7J5q8QZg9q5K2m';
    $attemptsLeft = $rateCheck['remaining'] - 1;
    $warnText = ($attemptsLeft > 0) 
        ? " (Απομένουν {$attemptsLeft} προσπάθειες πριν το προσωρινό κλείδωμα)" 
        : "";
    $genericInvalidMsg = 'Μη έγκυρα στοιχεία σύνδεσης (email ή κωδικός πρόσβασης).' . $warnText;

    if (!$user) {
        password_verify($password, $dummyHash);
        recordLoginAttempt($email, false);
        return ['success' => false, 'message' => $genericInvalidMsg];
    }

    if (!password_verify($password, $user['password_hash'] ?: $dummyHash)) {
        recordLoginAttempt($email, false);
        return ['success' => false, 'message' => $genericInvalidMsg];
    }

    if ($user['status'] === 'inactive') {
        recordLoginAttempt($email, false);
        return ['success' => false, 'message' => 'Ο λογαριασμός σας έχει απενεργοποιηθεί. Επικοινωνήστε με τη διεύθυνση.'];
    }

    if ($user['status'] === 'pending_activation' || empty($user['password_hash'])) {
        recordLoginAttempt($email, false);
        return ['success' => false, 'message' => 'Ο λογαριασμός δεν έχει ενεργοποιηθεί. Ελέγξτε το email σας για τον ορισμό κωδικού.'];
    }

    // Success! Clear failed attempts
    recordLoginAttempt($email, true);

    // Prevent session fixation by regenerating session ID
    session_regenerate_id(true);

    // Set Session Variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['auth_version'] = (int)$user['auth_version'];
    $_SESSION['password_fingerprint'] = hash('sha256', $user['password_hash']);
    $_SESSION['restore_generation'] = defined('APP_RESTORE_GENERATION')?APP_RESTORE_GENERATION:'';
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['last_db_ping'] = time();

    try {
        $db->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ?")->execute([$user['id']]);
    } catch (Throwable $e) {}

    return ['success' => true, 'role' => $user['role']];
}

function logout() {
    if (!empty($_SESSION['user_id'])) {
        try {
            getDB()->prepare("UPDATE users SET last_active_at = NULL WHERE id = ?")->execute([$_SESSION['user_id']]);
        } catch (Throwable $e) {}
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}

function createPasswordResetToken($userId, $type = 'reset') {
    $db = getDB();
    $token = bin2hex(random_bytes(32));
    // 24 hour expiry for invitation, 1 hour for reset
    $hours = ($type === 'invitation') ? 48 : 1;
    // Expiry and verification both use the database clock.

    // Invalidate old tokens for this user
    $db->prepare("DELETE FROM password_resets WHERE user_id = ? AND type = ?")->execute([$userId, $type]);

    $stmt = $db->prepare("INSERT INTO password_resets (user_id, token, type, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))");
    $stmt->execute([$userId, hash('sha256', $token), $type, $hours]);

    return $token;
}

function verifyResetToken($token) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT pr.*, u.name, u.email, u.role, u.status 
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    return $stmt->fetch();
}

function completePasswordReset($token, $newPassword) {
    if (strlen($newPassword)<6) return ['success'=>false,'message'=>'Ο κωδικός πρέπει να περιέχει τουλάχιστον 6 χαρακτήρες.'];
    $db=getDB();
    $db->beginTransaction();
    try {
        $stmt=$db->prepare('SELECT pr.id,pr.user_id FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE pr.token=? AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.status IN ("active","pending_activation") FOR UPDATE');
        $stmt->execute([hash('sha256',$token)]); $reset=$stmt->fetch();
        if (!$reset) { $db->rollBack(); return ['success'=>false,'message'=>'Ο σύνδεσμος επαναφοράς έχει λήξει ή δεν είναι έγκυρος.']; }
        $db->prepare("UPDATE users SET password_hash=?,status='active',auth_version=auth_version+1 WHERE id=?")->execute([password_hash($newPassword,PASSWORD_DEFAULT),$reset['user_id']]);
        $db->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$reset['user_id']]);
        $db->commit();
        return ['success'=>true,'message'=>'Ο κωδικός ορίστηκε επιτυχώς. Μπορείτε πλέον να συνδεθείτε.'];
    } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); return ['success'=>false,'message'=>'Δεν ήταν δυνατή η αλλαγή κωδικού.']; }
}
