<?php
// install.php - Interactive Web Installer for School Absences Application
require_once __DIR__.'/includes/installation.php';
$configFile = __DIR__ . '/config/database.php';
$lockFile = __DIR__ . '/config/installed.lock';

// Security Lockout: If system is already installed, prevent access to installer
if (file_exists($lockFile) || is_file($configFile)) {
    header('HTTP/1.1 403 Forbidden');
    ?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Η Εγκατάσταση έχει Ολοκληρωθεί</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=daylight-20261029-1">
</head>
<body class="auth-body">
    <div class="auth-card" style="max-width: 520px; text-align: center;">
        <div class="school-badge" style="margin: 0 auto 16px;">🔒</div>
        <h1 style="font-size: 22px; margin-bottom: 12px;">Οδηγός Εγκατάστασης Κλειδωμένος</h1>
        <p style="color: var(--text-muted); font-size: 14px; margin-bottom: 20px; line-height: 1.6;">
            Το σύστημα είναι ήδη εγκατεστημένο. Για λόγους ασφαλείας, η εκτέλεση του οδηγού εγκατάστασης έχει απενεργοποιηθεί αυτόματα.
        </p>
        <div class="alert alert-info" style="padding: 12px; font-size: 13px; text-align: left; margin-bottom: 24px; border-radius: 8px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;">
            ℹ️ <strong>Σημείωση Διαχειριστή:</strong> Για νέα εγκατάσταση χρησιμοποιήστε καθαρό φάκελο και νέα βάση. Για ανάκτηση χρησιμοποιήστε τη διαδικασία επαναφοράς backup. Μην αφαιρείτε το κλείδωμα της υπάρχουσας εγκατάστασης.
        </div>
        <a href="index.php" class="btn btn-primary" style="display: block; width: 100%; text-decoration: none; padding: 12px 20px;">
            Μετάβαση στη Σύνδεση (Login) &rarr;
        </a>
    </div>
</body>
</html>
    <?php
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')]);
    session_start();
}
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

// Public packages contain no enabled key. The local helper creates a per-installation hash.
$installAccessToken=getenv('APP_INSTALL_TOKEN') ?: '';
$installTokenFile=getenv('APP_INSTALL_TOKEN_FILE') ?: '';
if ($installAccessToken==='' && $installTokenFile!=='' && is_file($installTokenFile)) $installAccessToken=require $installTokenFile;
$installHash=is_file(__DIR__.'/config/install-access.php') ? require __DIR__.'/config/install-access.php' : '';
$legacy=is_string($installAccessToken) && strlen($installAccessToken)>=32;
if (!$legacy && !installationKeyHashValid($installHash)) {
    http_response_code(403);exit('Δεν έχει προετοιμαστεί προσωπικό κλειδί εγκατάστασης. Πριν από τη μεταφόρτωση ανοίξτε το tools/create-installation-key.html του πακέτου τοπικά και τοποθετήστε το παραγόμενο install-access.php στον φάκελο config/. Μην ανεβάσετε το INSTALLATION-KEY.txt στον δημόσιο χώρο.');
}
$installFingerprint=hash('sha256',$legacy ? $installAccessToken : $installHash);
$provided=$_POST['install_access_token']??null;
if (is_string($provided) && strlen($provided)<=256 && verifyInstallTransport()
    && ($legacy ? hash_equals($installAccessToken,$provided) : installationKeyMatches($provided,$installHash))
    && is_string($_POST['csrf_token']??null) && hash_equals($_SESSION['install_csrf'],$_POST['csrf_token'])) {
    session_regenerate_id(true);
    $_SESSION['install_authorized']=$installFingerprint;
    $_SESSION['install_access_at']=time();
}
if (!isset($_SESSION['install_authorized'],$_SESSION['install_access_at'])
    || !hash_equals($installFingerprint,$_SESSION['install_authorized'])
    || time()-(int)$_SESSION['install_access_at']>900 || !verifyInstallTransport()) {
    http_response_code(403);
    $csrf=htmlspecialchars($_SESSION['install_csrf'],ENT_QUOTES,'UTF-8');
    echo '<!doctype html><html lang="el"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/css/style.css"><title>Εξουσιοδότηση εγκατάστασης</title><body class="auth-body"><div class="auth-card"><h1>Εγκατάσταση Absences</h1><p>Πληκτρολογήστε το μοναδικό κλειδί από το αρχείο INSTALLATION-KEY.txt του πακέτου. Η εγκατάσταση δεν επιτρέπεται σε δημόσια σύνδεση χωρίς HTTPS.</p><form method="post"><input type="hidden" name="csrf_token" value="'.$csrf.'"><label>Κλειδί εγκατάστασης <input type="password" name="install_access_token" autocomplete="off" required></label><button class="btn btn-primary">Συνέχεια</button></form></div></body></html>';
    exit;
}
function verifyInstallTransport(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true) || PHP_SAPI==='cli';
}

// System Requirements Check
$requirements = [
    'php' => [
        'title' => 'PHP >= 8.2, 64-bit',
        'met' => version_compare(PHP_VERSION, '8.2.0', '>=') && PHP_INT_SIZE===8,
        'current' => PHP_VERSION
    ],
    'pdo_mysql' => [
        'title' => 'Επέκταση PDO MySQL',
        'met' => extension_loaded('pdo_mysql'),
        'current' => extension_loaded('pdo_mysql') ? 'Ενεργή' : 'Μη διαθέσιμη'
    ],
    'mbstring' => [
        'title' => 'Επέκταση Multibyte String (mbstring)',
        'met' => extension_loaded('mbstring'),
        'current' => extension_loaded('mbstring') ? 'Ενεργή' : 'Μη διαθέσιμη'
    ],
    'openssl' => [
        'title' => 'Επέκταση OpenSSL',
        'met' => extension_loaded('openssl'),
        'current' => extension_loaded('openssl') ? 'Ενεργή' : 'Μη διαθέσιμη'
    ],
    'writable_config' => [
        'title' => 'Δικαιώματα εγγραφής φακέλου config/',
        'met' => is_writable(__DIR__ . '/config'),
        'current' => is_writable(__DIR__ . '/config') ? 'Εγγράψιμος' : 'Μόνο ανάγνωση'
    ]
];
foreach (['zip','sodium','ctype','dom','fileinfo','filter','gd','iconv','libxml','simplexml','xml','xmlreader','xmlwriter','zlib'] as $extension) {
    $requirements[$extension]=['title'=>'PHP extension '.$extension,'met'=>extension_loaded($extension),'current'=>extension_loaded($extension)?'Ενεργή':'Μη διαθέσιμη'];
}
$allRequirementsMet = !in_array(false, array_column($requirements, 'met'), true);

/**
 * Transforms standard table names in SQL schema to include table prefix
 */
function prefixSchemaSql(string $sql, string $prefix): string {
    require_once __DIR__.'/includes/db.php';
    return AppPDO::prefixIdentifiers($sql,$prefix);
}

/**
 * Helper to suggest next prefix if collision exists (e.g. abs_ -> abs2_)
 */
function findNextAvailablePrefix(PDO $pdo, string $prefix): string {
    $base = rtrim($prefix, '_');
    if (preg_match('/^(.*?)(\d+)$/', $base, $m)) {
        $stem = $m[1];
        $num = (int)$m[2];
    } else {
        $stem = $base;
        $num = 1;
    }
    do {
        $num++;
        $suggested = $stem . $num . '_';
        $stmtCheck = $pdo->query("SHOW TABLES LIKE '" . addcslashes($suggested, '%_') . "%'");
        $cnt = $stmtCheck->rowCount();
    } while ($cnt > 0);
    return $suggested;
}

// -------------------------------------------------------------------------
// AJAX HANDLER: TEST CONNECTION & PREFIX COLLISION
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'test_connection') {
    header('Content-Type: application/json; charset=utf-8');
    
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['install_csrf'] ?? '', $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Μη έγκυρο αίτημα ασφαλείας (CSRF Token mismatch).']);
        exit;
    }

    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? 'school_absences');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';
    $rawPrefix = trim($_POST['db_prefix'] ?? 'abs_');

    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $rawPrefix);
    if (!empty($prefix) && !str_ends_with($prefix, '_')) {
        $prefix .= '_';
    }

    if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $dbHost) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbName) || $dbPort<1 || $dbPort>65535 || empty($dbUser) || strlen($prefix ?? $dbPrefix ?? '')>40) {
        echo json_encode(['success' => false, 'message' => 'Παρακαλώ συμπληρώστε τα πεδία Host, Όνομα Βάσης και Χρήστης.']);
        exit;
    }

    try {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ];
        $portPart = $dbPort ? ";port=" . $dbPort : "";
        
        // 1. Test Server Connection
        try {
            $pdo = new PDO("mysql:host={$dbHost}{$portPart};charset=utf8mb4", $dbUser, $dbPass, $options);
        } catch (PDOException $e) {
            $err = $e->getMessage();
            if (str_contains($err, 'Access denied')) {
                $friendly = 'Αποτυχία πιστοποίησης: Λάθος όνομα χρήστη (User) ή κωδικός πρόσβασης (Password).';
            } elseif (str_contains($err, 'getaddrinfo') || str_contains($err, 'Unknown MySQL server')) {
                $friendly = "Ο διακομιστής '{$dbHost}' δεν βρέθηκε. Ελέγξτε τη διεύθυνση Host.";
            } elseif (str_contains($err, 'Connection refused')) {
                $friendly = "Η σύνδεση απορρίφθηκε. Βεβαιωθείτε ότι ο MySQL Server είναι ενεργός στη θύρα {$dbPort}.";
            } else {
                $friendly = 'Η σύνδεση MySQL απέτυχε.';
            }
            echo json_encode(['success' => false, 'message' => $friendly]);
            exit;
        }

        // 2. Check if Database exists
        $stmtDb = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmtDb->execute([$dbName]);
        $dbExists = (bool)$stmtDb->fetch();

        if (!$dbExists) {
            echo json_encode([
                'success' => true,
                'db_exists' => false,
                'prefix' => $prefix,
                'collision' => false,
                'message' => "🟢 Επιτυχής σύνδεση με τον διακομιστή MySQL! Η βάση δεδομένων '{$dbName}' δεν υπάρχει ακόμη και θα δημιουργηθεί αυτόματα κατά την εγκατάσταση."
            ]);
            exit;
        }

        // 3. Connect to Database & Check Prefix Collision
        $pdo->exec("USE `{$dbName}`");
        $collision = false;
        $suggestedPrefix = $prefix;
        $collisionCount = 0;

        if (!empty($prefix)) {
            $stmtTables = $pdo->query("SHOW TABLES LIKE '" . addcslashes($prefix, '%_') . "%'");
            $collisionCount = $stmtTables->rowCount();
            if ($collisionCount > 0) {
                $collision = true;
                $suggestedPrefix = findNextAvailablePrefix($pdo, $prefix);
            }
        }

        if ($collision) {
            echo json_encode([
                'success' => true,
                'db_exists' => true,
                'collision' => true,
                'collision_count' => $collisionCount,
                'current_prefix' => $prefix,
                'suggested_prefix' => $suggestedPrefix,
                'message' => "⚠️ Προσοχή: Βρέθηκαν {$collisionCount} υπάρχοντες πίνακες με το πρόθεμα '{$prefix}' στη βάση '{$dbName}'. Για την προστασία των δεδομένων σας, προτάθηκε αυτόματα το πρόθεμα '{$suggestedPrefix}'."
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'db_exists' => true,
                'collision' => false,
                'current_prefix' => $prefix,
                'suggested_prefix' => $prefix,
                'message' => "🟢 Επιτυχής σύνδεση! Η βάση '{$dbName}' είναι έτοιμη και το πρόθεμα '{$prefix}' είναι ελεύθερο για χρήση."
            ]);
        }
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Ο έλεγχος σύνδεσης απέτυχε.']);
        exit;
    }
}

// -------------------------------------------------------------------------
// FORM DEFAULTS & SUBMISSION LOGIC
// -------------------------------------------------------------------------
$defaultHost = 'localhost';
$defaultPort = 3306;
$defaultDbName = 'school_absences';
$defaultDbUser = 'root';
$defaultDbPass = '';
$defaultPrefix = 'abs_';

// Load existing config if available
if (file_exists($configFile)) {
    @include_once $configFile;
    if (defined('DB_HOST')) $defaultHost = DB_HOST;
    if (defined('DB_PORT')) $defaultPort = DB_PORT;
    if (defined('DB_NAME')) $defaultDbName = DB_NAME;
    if (defined('DB_USER')) $defaultDbUser = DB_USER;
    // Never prefill a stored database password into HTML.
    if (defined('DB_PREFIX')) $defaultPrefix = DB_PREFIX;
}

$message = '';
$error = '';
$installedSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    $installMutex=fopen(__DIR__.'/config/install.mutex','c');
    if (!$installMutex || !flock($installMutex,LOCK_EX|LOCK_NB)) { http_response_code(409); exit('Η εγκατάσταση εκτελείται ήδη.'); }
    if (is_file($configFile) || is_file($lockFile)) { http_response_code(403); exit('Υπάρχει ήδη διαμόρφωση εγκατάστασης.'); }
    if (!hash_equals($_SESSION['install_csrf'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Μη έγκυρο αίτημα (CSRF token mismatch). Παρακαλώ ανανεώστε τη σελίδα και δοκιμάστε ξανά.';
    } elseif (!$allRequirementsMet) {
        $error = 'Δεν πληρούνται όλες οι απαιτήσεις του συστήματος. Παρακαλώ ελέγξτε τις σημειώσεις.';
    } else {
        // Database settings
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? 'school_absences');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = $_POST['db_pass'] ?? '';
        $rawPrefix = trim($_POST['db_prefix'] ?? 'abs_');
        
        $dbPrefix = preg_replace('/[^a-zA-Z0-9_]/', '', $rawPrefix);
        if (!empty($dbPrefix) && !str_ends_with($dbPrefix, '_')) {
            $dbPrefix .= '_';
        }

        // Admin & School settings
        $adminName = trim($_POST['admin_name'] ?? 'Γραμματεία Σχολείου');
        $adminEmail = trim($_POST['admin_email'] ?? 'admin@school.gr');
        $adminPass = $_POST['admin_password'] ?? '';
        $schoolName = trim($_POST['school_name'] ?? '1ο Γενικό Λύκειο');

        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $dbHost) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbName) || $dbPort<1 || $dbPort>65535 || empty($dbUser) || strlen($prefix ?? $dbPrefix ?? '')>40) {
            $error = 'Παρακαλώ συμπληρώστε τα υποχρεωτικά πεδία της βάσης δεδομένων (Host, Όνομα Βάσης, Χρήστης).';
        } elseif (empty($adminEmail) || empty($adminPass)) {
            $error = 'Παρακαλώ συμπληρώστε το email και τον κωδικό του διαχειριστή.';
        } elseif (strlen($adminPass) < 6) {
            $error = 'Ο κωδικός πρόσβασης διαχειριστή πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
        } else {
            try {
                $privateInput=$_POST['private_dir']??installationPrivateDefault();
                $baseUrl=$_POST['app_base_url']??'';
                if (!is_string($privateInput) || !is_string($baseUrl)) throw new RuntimeException('Μη έγκυρη διαδρομή ή διεύθυνση εφαρμογής.');
                $baseUrl=rtrim(trim($baseUrl),'/');$urlParts=parse_url($baseUrl);
                if (!filter_var($baseUrl,FILTER_VALIDATE_URL) || (isset($urlParts['user']) || isset($urlParts['pass'])) || isset($urlParts['query']) || isset($urlParts['fragment'])
                    || !(($urlParts['scheme']??'')==='https' || (($urlParts['scheme']??'')==='http' && in_array($urlParts['host']??'',['localhost','127.0.0.1','[::1]'],true)))) throw new RuntimeException('Ορίστε πλήρη διεύθυνση HTTPS (ή HTTP μόνο για localhost).');
                $privateDirectory=installationPrivateDirectory(trim($privateInput));
                // Step 1: Connect to MySQL server
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 5
                ];
                $portPart = $dbPort ? ";port=" . $dbPort : "";
                
                try {
                    $pdo = new PDO("mysql:host={$dbHost}{$portPart};charset=utf8mb4", $dbUser, $dbPass, $options);
                } catch (PDOException $e) {
                    $err = $e->getMessage();
                    if (str_contains($err, 'Access denied')) {
                        throw new Exception('Αποτυχία πιστοποίησης: Λάθος όνομα χρήστη (User) ή κωδικός πρόσβασης (Password).');
                    } elseif (str_contains($err, 'getaddrinfo') || str_contains($err, 'Unknown MySQL server')) {
                        throw new Exception("Ο διακομιστής '{$dbHost}' δεν βρέθηκε. Ελέγξτε τη διεύθυνση Host.");
                    } else {
                        throw new Exception('Η σύνδεση MySQL απέτυχε.');
                    }
                }

                // Step 2: Create DB if not exists and select it
                try {$pdo->exec("USE `{$dbName}`");} catch (PDOException $missing) {
                    if ((int)($missing->errorInfo[1]??0)!==1049) throw $missing;
                    $pdo->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $pdo->exec("USE `{$dbName}`");
                }

                // Step 3: Prefix Collision Safeguard
                $prefixChangedNotice = '';
                if (!empty($dbPrefix)) {
                    $stmtCheck = $pdo->query("SHOW TABLES LIKE '" . addcslashes($dbPrefix, '%_') . "%'");
                    if ($stmtCheck->rowCount() > 0) {
                        $originalPrefix = $dbPrefix;
                        $dbPrefix = findNextAvailablePrefix($pdo, $dbPrefix);
                        $prefixChangedNotice = " (Σημείωση: Επειδή το πρόθεμα '{$originalPrefix}' χρησιμοποιείται ήδη, εφαρμόστηκε αυτόματα το νέο ασφαλές πρόθεμα '{$dbPrefix}').";
                    }
                }

                // Step 4: Write config/database.php
                $configContent = "<?php\n"
                    . "// Database configuration for School Absences Application\n"
                    . "// Generated automatically by install.php on " . date('Y-m-d H:i:s') . "\n\n"
                    . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
                    . "define('DB_PORT', " . (int)$dbPort . ");\n"
                    . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
                    . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
                    . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
                    . "define('DB_CHARSET', 'utf8mb4');\n"
                    . "define('DB_PREFIX', " . var_export($dbPrefix, true) . ");\n";

                foreach (['maintenance','technical-errors'] as $folder) {
                    if (!is_dir($privateDirectory.'/'.$folder) && !mkdir($privateDirectory.'/'.$folder,0700)) throw new RuntimeException('Δεν δημιουργήθηκαν οι ιδιωτικοί υποφάκελοι.');
                }
                $privateConfig=$configContent
                    ."define('APP_ENV','production');\n"
                    ."define('APP_DEBUG',false);\n"
                    ."define('APP_BASE_URL',".var_export($baseUrl,true).");\n"
                    ."define('APP_MASTER_KEY_FILE',".var_export($privateDirectory.'/smtp_master.key',true).");\n"
                    ."define('APP_MAINTENANCE_DIR',".var_export($privateDirectory.'/maintenance',true).");\n"
                    ."define('APP_TECHNICAL_LOG_DIR',".var_export($privateDirectory.'/technical-errors',true).");\n";
                installationWriteNew($privateDirectory.'/smtp_master.key',base64_encode(random_bytes(32)));
                installationWriteNew($privateDirectory.'/config.php',$privateConfig);
                installationWriteNew($configFile,"<?php\nrequire ".var_export($privateDirectory.'/config.php',true).";\n");
                installationWriteNew(__DIR__.'/config/private-config.php',"<?php return ".var_export($privateDirectory.'/config.php',true).";\n");

                // Step 5: Read and transform schema.sql with prefix
                $schemaFile = null;
                $schemaCandidates = [
                    __DIR__ . '/schema.sql',
                    dirname(__DIR__) . '/database/schema.sql',
                    __DIR__ . '/database/schema.sql'
                ];
                foreach ($schemaCandidates as $candidate) {
                    if (file_exists($candidate)) {
                        $schemaFile = $candidate;
                        break;
                    }
                }
                if (!$schemaFile) {
                    throw new Exception("Δεν βρέθηκε το αρχείο δομής βάσης (schema.sql).");
                }
                $rawSql = file_get_contents($schemaFile);
                $rawSql = preg_replace('/^(\xEF\xBB\xBF|\xFE\xFF|\xFF\xFE)/', '', $rawSql);
                $rawSql = preg_replace('/^\x{FEFF}/u', '', $rawSql);

                $cleanSql = prefixSchemaSql($rawSql, $dbPrefix);

                // Filter comment lines
                $lines = explode("\n", $cleanSql);
                $cleanLines = [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                    $cleanLines[] = $line;
                }
                $statements = explode(';', implode("\n", $cleanLines));
                foreach ($statements as $stmtSql) {
                    $stmtSql = trim($stmtSql);
                    if (!empty($stmtSql)) {
                        $pdo->exec($stmtSql);
                    }
                }

                // Step 6: Update school name setting
                $stmt = $pdo->prepare("UPDATE `{$dbPrefix}settings` SET setting_value = ? WHERE setting_key = 'school_name'");
                $stmt->execute([$schoolName]);

                // Step 7: Create administrator user
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO `{$dbPrefix}users` (name, email, password_hash, role, status, auth_version)
                    VALUES (?, ?, ?, 'admin', 'active', 1)
                    ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = 'admin', status = 'active'
                ");
                $stmt->execute([$adminName, $adminEmail, $hash]);

                // Step 8: Lock Installer
                $lockWritten=file_put_contents($lockFile, "Installed on " . date('Y-m-d H:i:s') . "\nTable Prefix: {$dbPrefix}\nDo not delete this file unless you wish to reinstall the system.\n");

                if ($lockWritten===false || !is_file($lockFile)) throw new RuntimeException('Αδυναμία κλειδώματος εγκατάστασης. Η υπάρχουσα διαμόρφωση αποκλείει την επανεκτέλεση.');
                if (is_file(__DIR__.'/config/install-access.php')) @unlink(__DIR__.'/config/install-access.php');
                unset($_SESSION['install_authorized'],$_SESSION['install_access_at']);
                $message = "Η εγκατάσταση ολοκληρώθηκε επιτυχώς! Η βάση δεδομένων ρυθμίστηκε με πρόθεμα '{$dbPrefix}' και δημιουργήθηκε ο λογαριασμός διαχειριστή." . $prefixChangedNotice;
                $installedSuccess = true;
            } catch (Exception $e) {
                $error = $e instanceof PDOException ? 'Η βάση δεν μπορεί να ρυθμιστεί. Ελέγξτε στοιχεία και δικαιώματα. Αν δημιουργήθηκαν αρχεία, απαιτείται έλεγχος πριν από επανάληψη.' : $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Οδηγός Εγκατάστασης - Παρουσιολόγιο Σχολείου</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=daylight-20261029-1">
    <style>
        .install-card {
            background: var(--surface, #ffffff);
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            border: 1px solid var(--border, #e2e8f0);
            padding: 32px;
            width: 100%;
            max-width: 680px;
            margin: 30px auto;
        }
        .section-box {
            background: #f8fafc;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-main, #1e293b);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--border, #e2e8f0);
            padding-bottom: 10px;
        }
        .test-btn {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: #334155;
            font-weight: 600;
            font-size: 13px;
            border-radius: 8px;
            padding: 9px 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .test-btn:hover {
            border-color: #6366f1;
            color: #4f46e5;
            background: #f5f3ff;
        }
        .test-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .feedback-banner {
            display: none;
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
        }
        .feedback-success {
            display: block;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .feedback-warning {
            display: block;
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .feedback-error {
            display: block;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(79, 70, 229, 0.2);
            border-radius: 50%;
            border-top-color: #4f46e5;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .req-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 500;
        }
        .req-ok { background: #dcfce7; color: #15803d; }
        .req-fail { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body class="auth-body">
    <div class="install-card">
        <div class="auth-header" style="text-align: center; margin-bottom: 24px;">
            <div class="school-badge" style="margin: 0 auto 12px; font-size: 32px;">📚</div>
            <h1 style="font-size: 24px; font-weight: 700; margin-bottom: 6px;">Οδηγός Εγκατάστασης</h1>
            <p style="color: var(--text-muted); font-size: 14px;">Ηλεκτρονικό Παρουσιολόγιο &amp; Ειδοποιήσεις Απουσιών Σχολείου</p>
        </div>

        <?php if ($installedSuccess): ?>
            <div class="alert alert-success" style="padding: 20px; border-radius: 12px; background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; margin-bottom: 24px;">
                <div style="font-weight: 700; font-size: 16px; margin-bottom: 8px;">🎉 Η Εγκατάσταση Ολοκληρώθηκε Επιτυχώς!</div>
                <p style="margin-bottom: 16px; line-height: 1.5;"><?= htmlspecialchars($message) ?></p>
                <div style="background: rgba(255,255,255,0.7); padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 18px;">
                    <strong>Στοιχεία Σύνδεσης Διαχειριστή:</strong><br>
                    Email: <code><?= htmlspecialchars($_POST['admin_email'] ?? '') ?></code><br>
                    Κωδικός: <em>(αυτός που ορίσατε στη φόρμα)</em>
                </div>
                <a href="index.php" class="btn btn-primary" style="display: block; width: 100%; text-align: center; text-decoration: none; padding: 12px; font-size: 15px; font-weight: 600;">
                    Μετάβαση στη Σελίδα Σύνδεσης (Login) &rarr;
                </a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="padding: 14px; border-radius: 10px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; margin-bottom: 20px; font-size: 14px;">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- System Requirements Accordion/Box -->
            <details style="margin-bottom: 20px; background: #f1f5f9; padding: 12px 16px; border-radius: 10px; font-size: 13px; border: 1px solid #e2e8f0;" <?= $allRequirementsMet ? '' : 'open' ?>>
                <summary style="font-weight: 600; cursor: pointer; color: #334155; display: flex; justify-content: space-between; align-items: center;">
                    <span>⚙️ Έλεγχος Απαιτήσεων Συστήματος Server</span>
                    <span class="req-badge <?= $allRequirementsMet ? 'req-ok' : 'req-fail' ?>">
                        <?= $allRequirementsMet ? '✓ Όλα Έτοιμα' : '⚠️ Απαιτούνται Ενέργειες' ?>
                    </span>
                </summary>
                <div style="margin-top: 12px; display: grid; gap: 8px;">
                    <?php foreach ($requirements as $key => $req): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px;">
                            <span><?= htmlspecialchars($req['title']) ?></span>
                            <span class="req-badge <?= $req['met'] ? 'req-ok' : 'req-fail' ?>">
                                <?= $req['met'] ? '✓ ' . htmlspecialchars($req['current']) : '✗ ' . htmlspecialchars($req['current']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>

            <form method="POST" action="install.php" id="installForm" class="auth-form">
                <input type="hidden" name="csrf_token" id="csrfToken" value="<?= htmlspecialchars($_SESSION['install_csrf'] ?? '') ?>">

                <!-- ΕΝΟΤΗΤΑ 1: ΡΥΘΜΙΣΕΙΣ ΒΑΣΗΣ ΔΕΔΟΜΕΝΩΝ -->
                <div class="section-box">
                    <div class="section-title">
                        <span>🗄️</span> 1. Ρυθμίσεις Βάσης Δεδομένων (MySQL)
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label>Διακομιστής (Host): *</label>
                            <input type="text" name="db_host" id="dbHost" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? $defaultHost) ?>" placeholder="localhost" required>
                        </div>
                        <div class="form-group">
                            <label>Θύρα (Port):</label>
                            <input type="number" name="db_port" id="dbPort" class="form-control" value="<?= htmlspecialchars($_POST['db_port'] ?? $defaultPort) ?>" placeholder="3306">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Όνομα Βάσης Δεδομένων (Database Name): *</label>
                        <input type="text" name="db_name" id="dbName" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? $defaultDbName) ?>" placeholder="π.χ. school_absences" required>
                        <small style="color: var(--text-muted); font-size: 12px;">Αν η βάση δεδομένων δεν υπάρχει, θα δημιουργηθεί αυτόματα εφόσον υπάρχουν δικαιώματα.</small>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label>Χρήστης MySQL (User): *</label>
                            <input type="text" name="db_user" id="dbUser" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? $defaultDbUser) ?>" placeholder="π.χ. root" required>
                        </div>
                        <div class="form-group">
                            <label>Κωδικός MySQL (Password):</label>
                            <input type="password" name="db_pass" id="dbPass" class="form-control" value="" placeholder="(κενό αν δεν υπάρχει)">
                        </div>
                    </div>

                    <!-- ΠΡΟΘΕΜΑ ΠΙΝΑΚΩΝ (TABLE PREFIX) -->
                    <div class="form-group" style="margin-bottom: 14px;">
                        <label style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Πρόθεμα Πινάκων (Table Prefix): *</span>
                            <span style="font-size: 11px; font-weight: normal; color: var(--text-muted);">Ιδανικό για κοινή βάση με WordPress</span>
                        </label>
                        <input type="text" name="db_prefix" id="dbPrefix" class="form-control" value="<?= htmlspecialchars($_POST['db_prefix'] ?? $defaultPrefix) ?>" placeholder="π.χ. abs_" pattern="[a-zA-Z0-9_]+" title="Μόνο λατινικοί χαρακτήρες, αριθμοί και κάτω παύλες" required>
                        <small style="color: var(--text-muted); font-size: 12px;">
                            Όλοι οι πίνακες της εφαρμογής θα ξεκινούν με αυτό το πρόθεμα (π.χ. <code>abs_students</code>, <code>abs_attendance</code>).
                        </small>
                    </div>

                    <!-- ΔΟΚΙΜΗ ΣΥΝΔΕΣΗΣ BUTTON -->
                    <div style="display: flex; align-items: center; gap: 12px; margin-top: 8px;">
                        <button type="button" id="btnTestConnection" class="test-btn">
                            <span id="testBtnIcon">🔍</span>
                            <span id="testBtnText">Δοκιμή Σύνδεσης &amp; Ελέγχου Προθέματος</span>
                        </button>
                    </div>

                    <!-- FEEDBACK BANNER -->
                    <div id="testFeedback" class="feedback-banner"></div>
                </div>

                <div class="section-box">
                    <div class="section-title">Ιδιωτική αποθήκευση &amp; διεύθυνση</div>
                    <div class="form-group"><label for="appBaseUrl">Διεύθυνση εφαρμογής (HTTPS, ή HTTP για localhost)</label><input id="appBaseUrl" class="form-control" name="app_base_url" type="url" required value="<?= htmlspecialchars(is_string($_POST['app_base_url']??null)?$_POST['app_base_url']:((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/install.php')),'/')),ENT_QUOTES,'UTF-8') ?>"></div>
                    <div class="form-group"><label for="privateDir">Ιδιωτικός φάκελος εκτός δημόσιου χώρου</label><input id="privateDir" class="form-control" name="private_dir" required value="<?= htmlspecialchars(is_string($_POST['private_dir']??null)?$_POST['private_dir']:installationPrivateDefault(),ENT_QUOTES,'UTF-8') ?>"></div>
                    <p>Ο οδηγός δημιουργεί αυτόματα νέο κλειδί SMTP, ιδιωτικές ρυθμίσεις και φακέλους backup/τεχνικών σφαλμάτων. Αν το hosting δεν επιτρέπει τη δημιουργία, ζητήστε εγγράψιμο ιδιωτικό φάκελο και συμπληρώστε τη διαδρομή του. Δεν θα αποθηκευτούν μυστικά στον δημόσιο χώρο.</p>
                </div>
                <!-- ΕΝΟΤΗΤΑ 2: ΣΤΟΙΧΕΙΑ ΣΧΟΛΕΙΟΥ & ΔΙΑΧΕΙΡΙΣΤΗ -->
                <div class="section-box">
                    <div class="section-title">
                        <span>🏫</span> 2. Στοιχεία Σχολείου &amp; Διαχειριστή
                    </div>

                    <div class="form-group">
                        <label>Επίσημη Ονομασία Σχολείου: *</label>
                        <input type="text" name="school_name" class="form-control" value="<?= htmlspecialchars($_POST['school_name'] ?? '1ο Γενικό Λύκειο') ?>" placeholder="π.χ. 1ο ΓΕΛ Πατρών" required>
                    </div>

                    <div class="form-group">
                        <label>Ονοματεπώνυμο Διαχειριστή: *</label>
                        <input type="text" name="admin_name" class="form-control" value="<?= htmlspecialchars($_POST['admin_name'] ?? 'Γραμματεία Σχολείου') ?>" placeholder="π.χ. Γραμματεία ή Ονοματεπώνυμο" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Email Διαχειριστή (Login): *</label>
                            <input type="email" name="admin_email" class="form-control" value="<?= htmlspecialchars($_POST['admin_email'] ?? 'admin@school.gr') ?>" placeholder="admin@school.gr" required>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label>Κωδικός Πρόσβασης: *</label>
                            <div style="position: relative;">
                                <input type="password" name="admin_password" id="adminPassword" class="form-control" placeholder="Τουλάχιστον 6 χαρακτήρες" minlength="6" required style="padding-right: 40px;">
                                <button type="button" id="togglePassBtn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 16px;">👁️</button>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" name="install" class="btn btn-primary btn-block" style="padding: 14px; font-weight: 700; font-size: 16px; border-radius: 10px; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);">
                    🚀 Ολοκλήρωση &amp; Έναρξη Εγκατάστασης
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Password visibility toggle
        const togglePassBtn = document.getElementById('togglePassBtn');
        const adminPassInput = document.getElementById('adminPassword');
        if (togglePassBtn && adminPassInput) {
            togglePassBtn.addEventListener('click', function() {
                const isPass = adminPassInput.type === 'password';
                adminPassInput.type = isPass ? 'text' : 'password';
                togglePassBtn.textContent = isPass ? '🙈' : '👁️';
            });
        }

        // Interactive Connection & Prefix Test
        const btnTest = document.getElementById('btnTestConnection');
        const feedback = document.getElementById('testFeedback');
        const prefixInput = document.getElementById('dbPrefix');

        if (btnTest) {
            btnTest.addEventListener('click', async function() {
                const host = document.getElementById('dbHost').value.trim();
                const port = document.getElementById('dbPort').value.trim();
                const dbName = document.getElementById('dbName').value.trim();
                const user = document.getElementById('dbUser').value.trim();
                const pass = document.getElementById('dbPass').value;
                const prefix = prefixInput.value.trim();
                const csrf = document.getElementById('csrfToken').value;

                if (!host || !dbName || !user) {
                    feedback.className = 'feedback-banner feedback-warning';
                    feedback.innerHTML = '⚠️ Παρακαλώ συμπληρώστε πρώτα τα πεδία Host, Όνομα Βάσης και Χρήστης.';
                    return;
                }

                // UI loading state
                btnTest.disabled = true;
                const origIcon = document.getElementById('testBtnIcon').textContent;
                document.getElementById('testBtnIcon').innerHTML = '<span class="spinner"></span>';
                document.getElementById('testBtnText').textContent = 'Έλεγχος σύνδεσης...';
                feedback.className = 'feedback-banner';
                feedback.style.display = 'none';

                const formData = new FormData();
                formData.append('db_host', host);
                formData.append('db_port', port);
                formData.append('db_name', dbName);
                formData.append('db_user', user);
                formData.append('db_pass', pass);
                formData.append('db_prefix', prefix);
                formData.append('csrf_token', csrf);

                try {
                    const res = await fetch('install.php?action=test_connection', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await res.json();

                    if (!data.success) {
                        feedback.className = 'feedback-banner feedback-error';
                        feedback.innerHTML = '🔴 ' + escapeHtml(data.message);
                    } else if (data.collision) {
                        feedback.className = 'feedback-banner feedback-warning';
                        feedback.innerHTML = escapeHtml(data.message);
                        // Automatically update the prefix field to the suggested prefix!
                        if (data.suggested_prefix) {
                            prefixInput.value = data.suggested_prefix;
                            prefixInput.style.backgroundColor = '#fef3c7';
                            setTimeout(() => {
                                prefixInput.style.backgroundColor = '';
                            }, 2000);
                        }
                    } else {
                        feedback.className = 'feedback-banner feedback-success';
                        feedback.innerHTML = escapeHtml(data.message);
                    }
                } catch (err) {
                    feedback.className = 'feedback-banner feedback-error';
                    feedback.innerHTML = '🔴 Σφάλμα επικοινωνίας με τον server κατά τον έλεγχο: ' + escapeHtml(err.message);
                } finally {
                    btnTest.disabled = false;
                    document.getElementById('testBtnIcon').textContent = origIcon;
                    document.getElementById('testBtnText').textContent = 'Δοκιμή Σύνδεσης & Ελέγχου Προθέματος';
                }
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
