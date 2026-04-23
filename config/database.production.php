<?php
/**
 * Database Configuration - PRODUCTION (Cloudways)
 * 
 * Copy this file to database.php and update with your Cloudways credentials
 * You can find these in: Cloudways > Application > Access Details
 */

define('DB_HOST', '161.35.43.5');  // Usually localhost on Cloudways
define('DB_NAME', 'ntzsmdzahq');  // From Cloudways panel
define('DB_USER', 'ntzsmdzahq');  // From Cloudways panel
define('DB_PASS', 'USn7trcSPh');  // From Cloudways panel
define('DB_CHARSET', 'utf8mb4');

// PDO options
define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
