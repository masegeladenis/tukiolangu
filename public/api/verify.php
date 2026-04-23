<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Utils;
use App\Services\VerificationService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Get QR data from request
    $input = json_decode(file_get_contents('php://input'), true);
    $qrData = $input['qr_data'] ?? $_GET['qr_data'] ?? '';
    
    if (empty($qrData)) {
        Utils::jsonResponse([
            'success' => false,
            'status' => 'error',
            'message' => 'No QR data provided'
        ], 400);
    }
    
    $verificationService = new VerificationService();
    $result = $verificationService->verify($qrData);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    Utils::jsonResponse([
        'success' => false,
        'status' => 'error',
        'message' => 'System error: ' . $e->getMessage()
    ], 500);
}
