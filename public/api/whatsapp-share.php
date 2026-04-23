<?php
/**
 * Generate WhatsApp Share URL API
 * Returns a WhatsApp share URL with pre-filled invitation message
 * User must manually send the message (no automation = no ban risk)
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Database\Connection;
use App\Services\WhatsAppService;

Session::start();

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$participantId = (int) ($input['participant_id'] ?? 0);
$logShare = (bool) ($input['log_share'] ?? true);

if ($participantId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant ID']);
    exit;
}

$db = Connection::getInstance();

// Get participant with event info
$participant = $db->queryOne("
    SELECT p.*, e.event_name, e.event_date, e.event_time, e.event_venue
    FROM participants p
    JOIN events e ON p.event_id = e.id
    WHERE p.id = :id AND p.status = 'active'
", ['id' => $participantId]);

if (!$participant) {
    echo json_encode(['success' => false, 'message' => 'Participant not found or inactive']);
    exit;
}

if (empty($participant['phone'])) {
    echo json_encode(['success' => false, 'message' => 'Participant has no phone number']);
    exit;
}

// Check if card exists
$cardExists = false;
if (!empty($participant['card_output_path'])) {
    $path = $participant['card_output_path'];
    if (file_exists($path) || file_exists(ROOT_PATH . '/' . $path)) {
        $cardExists = true;
    }
}

// Build event array
$event = [
    'event_name' => $participant['event_name'],
    'event_date' => $participant['event_date'],
    'event_time' => $participant['event_time'],
    'event_venue' => $participant['event_venue']
];

$whatsappService = new WhatsAppService();

try {
    $result = $whatsappService->generateShareUrl($participant, $event);
    
    if ($result['success']) {
        // Log the share attempt if requested
        if ($logShare) {
            $userId = Auth::user()['id'] ?? null;
            
            $db->execute("
                INSERT INTO whatsapp_logs (participant_id, event_id, phone, message_type, shared_by)
                VALUES (:participant_id, :event_id, :phone, 'invitation', :shared_by)
            ", [
                'participant_id' => $participantId,
                'event_id' => $participant['event_id'],
                'phone' => $result['phone'],
                'shared_by' => $userId
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'url' => $result['url'],
            'message' => 'WhatsApp share URL generated',
            'card_available' => $cardExists,
            'phone' => $result['phone']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to generate WhatsApp URL'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
