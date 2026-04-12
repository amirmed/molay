<?php
define('APP_NAME', 'MOLAY - Gestion des Factures');
define('DB_PATH', getenv('DB_PATH') ?: __DIR__ . '/../db/molay.db');
define('APP_VERSION', '1.0.0');
define('CURRENCY', 'DH');
define('COLOR_PRIMARY', '#F38E21');
define('COLOR_SECONDARY', '#919294');

// Session config
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 7200); // 2 hours
ini_set('session.cookie_lifetime', 7200);
ini_set('session.cookie_samesite', 'Strict');
session_start();

// Session timeout check (2 hours)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 7200)) {
    session_destroy();
    if (php_sapi_name() !== 'cli') {
        header('Location: index.php');
        exit;
    }
}
$_SESSION['last_activity'] = time();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Timezone Morocco
date_default_timezone_set('Africa/Casablanca');
