<?php
/**
 * Send SMS Invitations API
 * Sends SMS invitations to participants
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/sms.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Database\Connection;
use App\Services\SmsService;

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

$eventId = (int) ($input['event_id'] ?? 0);
$participantIds = $input['participant_ids'] ?? [];
$sendToAll = (bool) ($input['send_to_all'] ?? false);

if ($eventId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
    exit;
}

$db = Connection::getInstance();

// Get event details
$event = $db->queryOne("SELECT * FROM events WHERE id = :id", ['id' => $eventId]);

if (!$event) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

// Build query for participants
if ($sendToAll) {
    $participants = $db->query("
        SELECT * FROM participants 
        WHERE event_id = :event_id 
        AND phone IS NOT NULL 
        AND phone != ''
        AND status = 'active'
    ", ['event_id' => $eventId]);
} elseif (!empty($participantIds)) {
    $placeholders = [];
    $params = ['event_id' => $eventId];
    foreach ($participantIds as $index => $id) {
        $placeholders[] = ":id{$index}";
        $params["id{$index}"] = (int) $id;
    }
    $participants = $db->query("
        SELECT * FROM participants 
        WHERE event_id = :event_id 
        AND id IN (" . implode(',', $placeholders) . ")
        AND phone IS NOT NULL 
        AND phone != ''
        AND status = 'active'
    ", $params);
} else {
    echo json_encode(['success' => false, 'message' => 'No participants specified']);
    exit;
}

if (empty($participants)) {
    echo json_encode(['success' => false, 'message' => 'No participants with valid phone numbers found']);
    exit;
}

// Check for skip_sent option (default true - skip already sent)
$skipAlreadySent = !isset($input['force_resend']) || !$input['force_resend'];
$userId = Auth::user()['id'] ?? null;

// Get already sent participant IDs if skipping
$alreadySentIds = [];
if ($skipAlreadySent) {
    $participantIdList = array_column($participants, 'id');
    if (!empty($participantIdList)) {
        $placeholders = array_map(fn($i) => ":pid{$i}", array_keys($participantIdList));
        $params = [];
        foreach ($participantIdList as $i => $pid) {
            $params["pid{$i}"] = $pid;
        }
        $alreadySent = $db->query("
            SELECT DISTINCT participant_id 
            FROM sms_logs 
            WHERE participant_id IN (" . implode(',', $placeholders) . ")
            AND message_type = 'invitation'
            AND status = 'sent'
        ", $params);
        $alreadySentIds = array_column($alreadySent, 'participant_id');
    }
}

$smsService = new SmsService();
$sent = 0;
$failed = 0;
$skipped = 0;
$errors = [];

foreach ($participants as $participant) {
    // Skip if already sent (unless force resend)
    if ($skipAlreadySent && in_array($participant['id'], $alreadySentIds)) {
        $skipped++;
        continue;
    }
    
    try {
        $result = $smsService->sendInvitation($participant, $event);
        
        // Log the SMS
        $db->execute("
            INSERT INTO sms_logs (participant_id, event_id, phone, message_type, status, api_response, sent_by)
            VALUES (:participant_id, :event_id, :phone, 'invitation', :status, :api_response, :sent_by)
        ", [
            'participant_id' => $participant['id'],
            'event_id' => $eventId,
            'phone' => $participant['phone'],
            'status' => $result['success'] ? 'sent' : 'failed',
            'api_response' => json_encode($result),
            'sent_by' => $userId
        ]);
        
        if ($result['success']) {
            $sent++;
        } else {
            $failed++;
            $errors[] = $participant['name'] . ': ' . ($result['message'] ?? 'Unknown error');
        }
    } catch (Exception $e) {
        $failed++;
        $errors[] = $participant['name'] . ': ' . $e->getMessage();
        
        // Log failure
        $db->execute("
            INSERT INTO sms_logs (participant_id, event_id, phone, message_type, status, api_response, sent_by)
            VALUES (:participant_id, :event_id, :phone, 'invitation', 'failed', :api_response, :sent_by)
        ", [
            'participant_id' => $participant['id'],
            'event_id' => $eventId,
            'phone' => $participant['phone'],
            'api_response' => json_encode(['error' => $e->getMessage()]),
            'sent_by' => $userId
        ]);
    }
}

$total = count($participants);
$testMode = $smsService->isTestMode();

$message = $testMode 
    ? "Test mode: {$sent} SMS would be sent" 
    : "Successfully sent {$sent} SMS";

if ($skipped > 0) {
    $message .= ", {$skipped} skipped (already sent)";
}

echo json_encode([
    'success' => true,
    'message' => $message,
    'sent' => $sent,
    'failed' => $failed,
    'skipped' => $skipped,
    'total' => $total,
    'test_mode' => $testMode,
    'errors' => array_slice($errors, 0, 5) // Limit errors to first 5
]);
