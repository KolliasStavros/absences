<?php
// Application configuration & Security bootstrap
date_default_timezone_set('Europe/Athens');

// 1. Check for private production config outside webroot (PSD shared hosting best-practice)
$privateConfigCandidates = [
    getenv('APP_CONFIG_FILE') ?: '',
    is_file(__DIR__.'/private-config.php') ? require __DIR__.'/private-config.php' : '',
    dirname(__DIR__, 2) . '/private/config.php',
    dirname(__DIR__, 3) . '/private/config.php',
    __DIR__ . '/production.php'
];

$privateConfigLoaded = false;
foreach ($privateConfigCandidates as $candidate) {
    if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        $privateConfigLoaded = true;
        break;
    }
}

// 2. Fallback to local development files if private config was not found
if (!$privateConfigLoaded) {
    if (is_file(__DIR__ . '/local.php')) {
        require_once __DIR__ . '/local.php';
    }
    if (is_file(__DIR__ . '/database.php')) {
        require_once __DIR__ . '/database.php';
    }
}

// 3. Automatic installation redirect if system is not installed yet
// Maintenance is opt-in; no machine-specific paths or credentials are shipped.
if (is_file(__DIR__.'/maintenance.php')) require_once __DIR__.'/maintenance.php';
require_once __DIR__.'/../includes/lifecycle_guard.php';
lifecycleRequestGuard(dirname(__DIR__));

$lockFile = __DIR__ . '/installed.lock';
$installScript = dirname(__DIR__) . '/install.php';
if (!file_exists($lockFile) && file_exists($installScript) && php_sapi_name() !== 'cli') {
    $scriptFilename = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $appRootDir = str_replace('\\', '/', dirname(__DIR__));
    if (basename($scriptFilename) !== 'install.php') {
        $relPath = trim(str_replace($appRootDir, '', $scriptFilename), '/');
        $depth = substr_count($relPath, '/');
        $redirectUrl = str_repeat('../', max(0, $depth)) . 'install.php';
        header("Location: " . $redirectUrl);
        exit;
    }
}

require_once __DIR__ . '/../includes/security.php';

// Safe environment defaults
if (!defined('APP_ENV')) define('APP_ENV', 'production');
if (!defined('APP_DEBUG')) define('APP_DEBUG', false);

// Production Configuration Guard: fail-closed if required settings are missing
if (APP_ENV === 'production') {
    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS') || !defined('APP_BASE_URL')) {
        http_response_code(500);
        if (php_sapi_name() === 'cli') {
            fwrite(STDERR, "Σφάλμα: Η εφαρμογή δεν έχει ρυθμιστεί για παραγωγική λειτουργία. Λείπουν απαραίτητες παράμετροι διαμόρφωσης.\n");
        } else {
            echo "Σφάλμα: Η εφαρμογή δεν έχει ρυθμιστεί για παραγωγική λειτουργία. Λείπουν απαραίτητες παράμετροι διαμόρφωσης.\n";
        }
        exit(1);
    }
}

ini_set('display_errors', (APP_ENV === 'development' || (defined('APP_DEBUG') && APP_DEBUG === true)) ? '1' : '0');
ini_set('log_errors', '1');
technicalRegisterHandlers();

set_exception_handler(function(Throwable $e) {
    $message = safeError($e);
    http_response_code(500);
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $message]);
    } else {
        echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
});

// Store sessions outside the public site, independently of hosting defaults.
require_once __DIR__.'/../includes/session_storage.php';
prepareAbsencesSessionStorage(dirname(__DIR__));

// Secure Session Cookie Settings
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
                (defined('APP_ENV') && APP_ENV === 'production' && defined('APP_BASE_URL') && str_starts_with(APP_BASE_URL, 'https://'));
    
    // Configure session cookie params for maximum security
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $isSecure, true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// HTTP Security Headers
if (!headers_sent()) {
    header_remove("X-Powered-By");
    header("Cache-Control: no-store, private");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
}

define('APP_NAME', 'Παρουσιολόγιο Σχολείου');
$installedVersion='1.0.6';
$restoreGeneration='';
try {
    $maintenanceStore=lifecycleStorage(dirname(__DIR__));
    $versionData=is_file($maintenanceStore.'/version.json') ? json_decode(file_get_contents($maintenanceStore.'/version.json'),true) : null;
    if (is_string($versionData['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/D',$versionData['version'])) $installedVersion=$versionData['version'];
    if(is_file($maintenanceStore.'/restore-generation.json')) {
        $generationData=json_decode(file_get_contents($maintenanceStore.'/restore-generation.json'),true);
        if(!is_string($generationData['generation']??null) || !preg_match('/^[a-f0-9]{64}$/D',$generationData['generation']))throw new RuntimeException('Invalid restore generation');
        $restoreGeneration=$generationData['generation'];
    }
} catch (Throwable $e) {
    if(isset($maintenanceStore) && is_file($maintenanceStore.'/restore-generation.json')) {
        http_response_code(503);exit('Δεν διαβάζεται η κατάσταση επαναφοράς. Απαιτείται έλεγχος του ιδιωτικού φακέλου συντήρησης.');
    }
    /* Optional maintenance is not configured. */
}
define('APP_VERSION', $installedVersion);
define('APP_RESTORE_GENERATION', $restoreGeneration);

define('BASE_URL', rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : '', '/'));

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/greek_helper.php';
require_once __DIR__ . '/../includes/mailer.php';
