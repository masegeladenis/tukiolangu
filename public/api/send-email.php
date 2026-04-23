<?php
/**
 * Send Email to Individual Participant API
 * Supports single participant email with optional resend
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/mail.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Database\Connection;
use App\Services\EmailService;

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

if ($participantId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant ID']);
    exit;
}

$db = Connection::getInstance();

// Get participant with event info
$participant = $db->queryOne("
    SELECT p.*, e.event_name, e.event_date, e.event_time, e.event_venue, e.event_code, e.description
    FROM participants p
    JOIN events e ON p.event_id = e.id
    WHERE p.id = :id AND p.status = 'active'
", ['id' => $participantId]);

if (!$participant) {
    echo json_encode(['success' => false, 'message' => 'Participant not found or inactive']);
    exit;
}

if (empty($participant['email'])) {
    echo json_encode(['success' => false, 'message' => 'Participant has no email address']);
    exit;
}

// Check if email was already sent (unless force resend)
if (!$forceResend) {
    $existingEmail = $db->queryOne("
        SELECT * FROM email_logs 
        WHERE participant_id = :participant_id 
        AND message_type = :message_type
        AND status = 'sent'
        ORDER BY sent_at DESC
        LIMIT 1
    ", [
        'participant_id' => $participantId,
        'message_type' => $messageType
    ]);
    
    if ($existingEmail) {
        $sentAt = date('M j, Y g:i A', strtotime($existingEmail['sent_at']));
        echo json_encode([
            'success' => false,
            'message' => "Email already sent on {$sentAt}. Use 'Resend' to send again.",
            'already_sent' => true,
            'sent_at' => $existingEmail['sent_at']
        ]);
        exit;
    }
}

// Build event array for email service
$event = [
    'id' => $participant['event_id'],
    'event_name' => $participant['event_name'],
    'event_code' => $participant['event_code'],
    'event_date' => $participant['event_date'],
    'event_time' => $participant['event_time'],
    'event_venue' => $participant['event_venue'],
    'description' => $participant['description']
];

// Get card path if available
$cardPath = null;
if (!empty($participant['card_output_path'])) {
    $path = $participant['card_output_path'];
    if (!file_exists($path)) {
        $path = ROOT_PATH . '/' . $participant['card_output_path'];
    }
    if (file_exists($path)) {
        $cardPath = $path;
    }
}

$emailService = new EmailService();
$userId = Auth::user()['id'] ?? null;

try {
    $result = $emailService->sendInvitationEmail($participant, $event, $cardPath);
    
    // Log the email
    $db->execute("
        INSERT INTO email_logs (participant_id, event_id, email, message_type, status, error_message, sent_by)
        VALUES (:participant_id, :event_id, :email, :message_type, :status, :error_message, :sent_by)
    ", [
        'participant_id' => $participantId,
        'event_id' => $participant['event_id'],
        'email' => $participant['email'],
        'message_type' => $messageType,
        'status' => $result['success'] ? 'sent' : 'failed',
        'error_message' => $result['success'] ? null : ($result['message'] ?? 'Unknown error'),
        'sent_by' => $userId
    ]);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Email sent successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to send email'
        ]);
    }
    
} catch (Exception $e) {
    // Log failure
    $db->execute("
        INSERT INTO email_logs (participant_id, event_id, email, message_type, status, error_message, sent_by)
        VALUES (:participant_id, :event_id, :email, :message_type, 'failed', :error_message, :sent_by)
    ", [
        'participant_id' => $participantId,
        'event_id' => $participant['event_id'],
        'email' => $participant['email'],
        'message_type' => $messageType,
        'error_message' => $e->getMessage(),
        'sent_by' => $userId
    ]);
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
