<?php
/**
 * Download Participant Card
 * Allows participants to download their invitation card via unique link
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Database\Connection;

$uniqueId = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';

if (empty($uniqueId)) {
    http_response_code(400);
    echo 'Invalid request: Missing ID';
    exit;
}

$db = Connection::getInstance();

// Find participant by unique_id
$participant = $db->queryOne("
    SELECT p.*, e.event_name, e.event_code 
    FROM participants p
    JOIN events e ON p.event_id = e.id
    WHERE p.unique_id = :unique_id 
    AND p.status = 'active'
", ['unique_id' => $uniqueId]);

if (!$participant) {
    http_response_code(404);
    echo 'Card not found';
    exit;
}

// Verify token if provided (optional extra security)
if (!empty($token)) {
    $expectedToken = substr(md5($participant['unique_id'] . $participant['created_at']), 0, 16);
    if ($token !== $expectedToken) {
        http_response_code(403);
        echo 'Invalid access token';
        exit;
    }
}

// Check if card exists
$cardPath = null;
if (!empty($participant['card_output_path'])) {
    // Handle both absolute and relative paths
    $path = $participant['card_output_path'];
    if (file_exists($path)) {
        $cardPath = $path;
    } else {
        $fullPath = ROOT_PATH . '/' . $participant['card_output_path'];
        if (file_exists($fullPath)) {
            $cardPath = $fullPath;
        }
    }
}

if (!$cardPath) {
    http_response_code(404);
    echo 'Invitation card not available yet. Please contact the event organizer.';
    exit;
}

// Get file info
$filename = $participant['event_code'] . '_' . $participant['unique_id'] . '_card.png';
$filesize = filesize($cardPath);
$mimeType = mime_content_type($cardPath);

// Check if this is a preview request (inline display) or download
$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $filesize);

if ($isPreview) {
    // For preview - display inline
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
} else {
    // For download - force download
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
}

// Output file
readfile($cardPath);
exit;
