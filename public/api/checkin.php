<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Services\VerificationService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Utils::jsonResponse([
        'success' => false,
        'message' => 'POST method required'
    ], 405);
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $uniqueId = $input['unique_id'] ?? '';
    $guestsCount = (int) ($input['guests_count'] ?? 1);
    $gateLocation = $input['gate_location'] ?? 'Main Entrance';
    $notes = $input['notes'] ?? '';
    
    // Get scanner user ID if logged in
    Session::start();
    $scannedBy = Auth::check() ? Auth::id() : null;
    
    if (empty($uniqueId)) {
        Utils::jsonResponse([
            'success' => false,
            'message' => 'Unique ID required'
        ], 400);
    }
    
    if ($guestsCount < 1) {
        Utils::jsonResponse([
            'success' => false,
            'message' => 'At least 1 guest required'
        ], 400);
    }
    
    $verificationService = new VerificationService();
    $result = $verificationService->checkIn(
        $uniqueId,
        $guestsCount,
        $scannedBy,
        $gateLocation,
        $notes
    );
    
    echo json_encode($result);
    
} catch (Exception $e) {
    Utils::jsonResponse([
        'success' => false,
        'status' => 'error',
        'message' => 'System error: ' . $e->getMessage()
    ], 500);
}
