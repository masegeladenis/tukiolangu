<?php

namespace App\Helpers;

class Utils
{
    /**
     * Generate unique ID for participant
     * Format: EVENTCODE-XXXX (e.g., REBBECADAY-A1B2)
     */
    public static function generateUniqueId(string $eventCode = ''): string
    {
        // Use event code if provided, otherwise use default prefix
        $prefix = !empty($eventCode) ? strtoupper($eventCode) : (defined('UNIQUE_ID_PREFIX') ? UNIQUE_ID_PREFIX : 'TUK');
        
        // Generate a simple 6-character alphanumeric code
        $random = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        
        return "{$prefix}-{$random}";
    }

    /**
     * Sanitize string for filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path components
        $filename = basename($filename);
        
        // Replace spaces with underscores
        $filename = str_replace(' ', '_', $filename);
        
        // Remove any characters that aren't alphanumeric, underscores, or dots
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', $filename);
        
        return $filename;
    }

    /**
     * Format file size
     */
    public static function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Format date
     */
    public static function formatDate(?string $date, string $format = 'M d, Y'): string
    {
        if (empty($date)) {
            return 'N/A';
        }
        return date($format, strtotime($date));
    }

    /**
     * Format datetime
     */
    public static function formatDateTime(?string $datetime, string $format = 'M d, Y h:i A'): string
    {
        if (empty($datetime)) {
            return 'N/A';
        }
        return date($format, strtotime($datetime));
    }

    /**
     * Redirect to URL
     * Accepts relative paths (e.g., '/login.php') or full URLs
     */
    public static function redirect(string $url): void
    {
        // If it's a relative path (starts with /), prepend base path
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $basePath = self::basePath();
            // Remove hardcoded paths if present
            $url = preg_replace('#^/tukioqrcode/public#', '', $url);
            $url = $basePath . $url;
        }
        header("Location: {$url}");
        exit;
    }

    /**
     * Get base URL
     */
    public static function baseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $basePath = defined('BASE_PATH') ? BASE_PATH : '/tukioqrcode/public';
        return "{$protocol}://{$host}{$basePath}";
    }

    /**
     * Get base path for URLs (without protocol/host)
     */
    public static function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : '/tukioqrcode/public';
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set('csrf_token', $token);
        return $token;
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken(string $token): bool
    {
        $storedToken = Session::get('csrf_token');
        return $storedToken && hash_equals($storedToken, $token);
    }

    /**
     * Get CSRF input field
     */
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Escape HTML
     */
    public static function escape(?string $string): string
    {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }

    /**
     * Generate slug from string
     */
    public static function slugify(string $string): string
    {
        $string = strtolower($string);
        $string = preg_replace('/[^a-z0-9]+/', '-', $string);
        $string = trim($string, '-');
        return $string;
    }

    /**
     * Get upload error message
     */
    public static function getUploadError(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds the upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds the MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];

        return $errors[$errorCode] ?? 'Unknown upload error';
    }

    /**
     * Check if request is AJAX
     */
    public static function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Send JSON response
     */
    public static function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Validate email
     */
    public static function isValidEmail(?string $email): bool
    {
        if (empty($email)) {
            return false;
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Create directory if not exists
     */
    public static function ensureDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return mkdir($path, 0755, true);
        }
        return true;
    }

    /**
     * Generate random alphanumeric string
     */
    public static function generateRandomString(int $length = 8): string
    {
        return strtoupper(bin2hex(random_bytes((int) ceil($length / 2))));
    }

    /**
     * Format phone number to Tanzania international format (255...)
     * Converts numbers starting with 0 to 255 format
     */
    public static function formatPhoneNumber(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        // Strip everything except digits (removes spaces, dashes, plus signs, etc.)
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Already has full country code: 255XXXXXXXXX (12 digits)
        if (str_starts_with($digits, '255') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        // Leading 0: 0XXXXXXXXX (10 digits) → strip leading 0
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+255' . substr($digits, 1);
        }

        // Bare 9-digit number (no country code, no leading 0): XXXXXXXXX
        if (strlen($digits) === 9) {
            return '+255' . $digits;
        }

        // Anything else: prepend +255 and return as-is (best-effort)
        return '+255' . $digits;
    }
}
