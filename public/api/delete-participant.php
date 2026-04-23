<?php
/**
 * Delete Participant API
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Database\Connection;

header('Content-Type: application/json');

Session::start();

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$participantId = (int) ($input['participant_id'] ?? 0);

if ($participantId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid participant ID']);
    exit;
}

try {
    $db = Connection::getInstance();
    
    // Check if participant exists
    $participant = $db->queryOne("
        SELECT id, unique_id, name, card_output_path, qr_code_path FROM participants WHERE id = :id
    ", ['id' => $participantId]);
    
    if (!$participant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Participant not found']);
        exit;
    }
    
    // Delete associated check-in logs (if table exists)
    try {
        $db->execute("DELETE FROM check_in_logs WHERE participant_id = :id", ['id' => $participantId]);
    } catch (Exception $e) {
        // Table might not exist, ignore
    }
    
    // Delete the participant
    $db->execute("DELETE FROM participants WHERE id = :id", ['id' => $participantId]);
    
    // Try to delete associated files (card and QR code)
    if (!empty($participant['card_output_path'])) {
        $cardPath = $participant['card_output_path'];
        if (!file_exists($cardPath)) {
            $cardPath = ROOT_PATH . '/' . $participant['card_output_path'];
        }
        if (file_exists($cardPath)) {
            @unlink($cardPath);
        }
    }
    
    if (!empty($participant['qr_code_path'])) {
        $qrPath = $participant['qr_code_path'];
        if (!file_exists($qrPath)) {
            $qrPath = ROOT_PATH . '/' . $participant['qr_code_path'];
        }
        if (file_exists($qrPath)) {
            @unlink($qrPath);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Participant deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Delete participant error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete participant']);
}
