<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Utils;
use App\Services\VerificationService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

try {
    $eventId = (int) ($_GET['event_id'] ?? 0);
    
    if ($eventId <= 0) {
        Utils::jsonResponse([
            'success' => false,
            'message' => 'Event ID required'
        ], 400);
    }
    
    $verificationService = new VerificationService();
    $stats = $verificationService->getEventStats($eventId);
    
    Utils::jsonResponse([
        'success' => true,
        'data' => $stats
    ]);
    
} catch (Exception $e) {
    Utils::jsonResponse([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage()
    ], 500);
}
