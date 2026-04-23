<?php
/**
 * Database Configuration
 */

define('DB_HOST', '161.35.43.5');
define('DB_NAME', 'ntzsmdzahq');
define('DB_USER', 'ntzsmdzahq');
define('DB_PASS', 'USn7trcSPh');
define('DB_CHARSET', 'utf8mb4');

// PDO options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
