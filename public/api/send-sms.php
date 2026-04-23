<?php
/**
 * Send SMS to Individual Participant API
 * Supports single participant SMS with optional resend
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

$participantId = (int) ($input['participant_id'] ?? 0);
$forceResend = (bool) ($input['force_resend'] ?? false);
$messageType = $input['message_type'] ?? 'invitation';
$customMessage = $input['custom_message'] ?? null;

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

// Check if SMS was already sent (unless force resend)
if (!$forceResend) {
    $existingSms = $db->queryOne("
        SELECT * FROM sms_logs 
        WHERE participant_id = :participant_id 
        AND message_type = :message_type
        AND status = 'sent'
        ORDER BY sent_at DESC
        LIMIT 1
    ", [
        'participant_id' => $participantId,
        'message_type' => $messageType
    ]);
    
    if ($existingSms) {
        $sentAt = date('M j, Y g:i A', strtotime($existingSms['sent_at']));
        echo json_encode([
            'success' => false,
            'message' => "SMS already sent on {$sentAt}. Use 'Resend' to send again.",
            'already_sent' => true,
            'sent_at' => $existingSms['sent_at']
        ]);
        exit;
    }
}

// Build event array for SMS service
$event = [
    'event_name' => $participant['event_name'],
    'event_date' => $participant['event_date'],
    'event_time' => $participant['event_time'],
    'event_venue' => $participant['event_venue']
];

$smsService = new SmsService();

try {
    if ($messageType === 'custom' && !empty($customMessage)) {
        // Send custom message
        $result = $smsService->send($participant['phone'], $customMessage);
    } else {
        // Send invitation message
        $result = $smsService->sendInvitation($participant, $event);
    }
    
    // Log the SMS
    $userId = Auth::user()['id'] ?? null;
    
    $db->execute("
        INSERT INTO sms_logs (participant_id, event_id, phone, message_type, status, api_response, sent_by)
        VALUES (:participant_id, :event_id, :phone, :message_type, :status, :api_response, :sent_by)
    ", [
        'participant_id' => $participantId,
        'event_id' => $participant['event_id'],
        'phone' => $participant['phone'],
        'message_type' => $messageType,
        'status' => $result['success'] ? 'sent' : 'failed',
        'api_response' => json_encode($result),
        'sent_by' => $userId
    ]);
    
    if ($result['success']) {
        $message = 'SMS sent successfully';
        if ($smsService->isTestMode()) {
            $message .= ' (Test Mode)';
        }
        echo json_encode([
            'success' => true,
            'message' => $message,
            'test_mode' => $smsService->isTestMode()
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to send SMS'
        ]);
    }
    
} catch (Exception $e) {
    // Log failure
    $db->execute("
        INSERT INTO sms_logs (participant_id, event_id, phone, message_type, status, api_response, sent_by)
        VALUES (:participant_id, :event_id, :phone, :message_type, 'failed', :api_response, :sent_by)
    ", [
        'participant_id' => $participantId,
        'event_id' => $participant['event_id'],
        'phone' => $participant['phone'],
        'message_type' => $messageType,
        'api_response' => json_encode(['error' => $e->getMessage()]),
        'sent_by' => Auth::user()['id'] ?? null
    ]);
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
