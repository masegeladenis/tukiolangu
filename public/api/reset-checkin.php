<?php
/**
 * Reset Check-in Status API
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
        SELECT p.id, p.total_guests, p.event_id FROM participants p WHERE p.id = :id
    ", ['id' => $participantId]);
    
    if (!$participant) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Participant not found']);
        exit;
    }
    
    // Reset check-in status
    $db->execute("
        UPDATE participants SET
            guests_checked_in = 0,
            guests_remaining = :total_guests,
            is_fully_checked_in = 0,
            first_checkin_at = NULL,
            last_checkin_at = NULL,
            updated_at = NOW()
        WHERE id = :id
    ", [
        'total_guests' => $participant['total_guests'],
        'id' => $participantId
    ]);
    
    // Log the reset action in checkin_logs table
    $db->execute("
        INSERT INTO checkin_logs (participant_id, event_id, action, guests_this_checkin, guests_before, guests_after, scanned_by, notes, created_at)
        VALUES (:participant_id, :event_id, 'manual_override', 0, 0, 0, :scanned_by, 'Check-in status reset by admin', NOW())
    ", [
        'participant_id' => $participantId,
        'event_id' => $participant['event_id'],
        'scanned_by' => Auth::id()
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Check-in status has been reset'
    ]);
    
} catch (Exception $e) {
    error_log("Reset check-in error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to reset check-in status: ' . $e->getMessage()]);
}
