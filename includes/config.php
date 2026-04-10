<?php
define('APP_NAME', 'MOLAY - Gestion des Factures');
define('DB_PATH', __DIR__ . '/../db/molay.db');
define('APP_VERSION', '1.0.0');
define('CURRENCY', 'MAD');
define('COLOR_PRIMARY', '#F38E21');
define('COLOR_SECONDARY', '#919294');

// Session config
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Timezone Morocco
date_default_timezone_set('Africa/Casablanca');
