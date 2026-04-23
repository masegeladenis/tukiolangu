<?php
/**
 * Application Configuration
 */

define('APP_NAME', 'Tukio Langu App');
define('APP_VERSION', '1.0.0');

// Auto-detect environment and base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Check if running in subdirectory (local) or root (production)
$scriptPath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (strpos($scriptPath, '/tukioqrcode') !== false) {
    // Local development with /tukioqrcode path
    define('APP_URL', $protocol . '://' . $host . '/tukioqrcode');
    define('BASE_PATH', '/tukioqrcode/public');
} elseif (strpos($scriptPath, '/public') === 0 || $scriptPath === '/public') {
    // Production - webroot is public_html, accessing via /public/
    define('APP_URL', $protocol . '://' . $host . '/public');
    define('BASE_PATH', '/public');
} else {
    // Production - webroot is set to public folder (recommended)
    define('APP_URL', $protocol . '://' . $host);
    define('BASE_PATH', '');
}

// Directory paths
define('ROOT_PATH', dirname(__DIR__));
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('OUTPUT_PATH', ROOT_PATH . '/output');
define('TEMPLATE_PATH', ROOT_PATH . '/templates');

// Upload directories
define('DESIGNS_PATH', UPLOAD_PATH . '/designs');
define('EXCEL_PATH', UPLOAD_PATH . '/excel');
define('CARDS_OUTPUT_PATH', OUTPUT_PATH . '/cards');
define('QRCODES_OUTPUT_PATH', OUTPUT_PATH . '/qrcodes');
define('PDF_OUTPUT_PATH', OUTPUT_PATH . '/pdf');

// QR Code settings
define('QR_DEFAULT_SIZE', 150);
define('QR_DEFAULT_MARGIN', 10);
define('QR_DEFAULT_POSITION', 'bottom-right');

// Unique ID settings
define('UNIQUE_ID_PREFIX', 'TUK');
define('UNIQUE_ID_YEAR', date('Y'));

// Session settings
define('SESSION_LIFETIME', 3600); // 1 hour

// Allowed file types
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/jpg']);
define('ALLOWED_EXCEL_TYPES', [
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv'
]);

// Max file sizes (in bytes)
define('MAX_IMAGE_SIZE', 10 * 1024 * 1024); // 10MB
define('MAX_EXCEL_SIZE', 5 * 1024 * 1024);  // 5MB
