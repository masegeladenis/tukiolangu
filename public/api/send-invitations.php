<?php
/**
 * Send Invitation Emails API
 * Sends invitation emails to participants
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

$eventId = (int) ($input['event_id'] ?? 0);
$participantIds = $input['participant_ids'] ?? []; // Empty means all participants
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
        AND email IS NOT NULL 
        AND email != ''
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
        AND email IS NOT NULL 
        AND email != ''
        AND status = 'active'
    ", $params);
} else {
    echo json_encode(['success' => false, 'message' => 'No participants specified']);
    exit;
}

if (empty($participants)) {
    echo json_encode(['success' => false, 'message' => 'No participants with valid email addresses found']);
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
            FROM email_logs 
            WHERE participant_id IN (" . implode(',', $placeholders) . ")
            AND message_type = 'invitation'
            AND status = 'sent'
        ", $params);
        $alreadySentIds = array_column($alreadySent, 'participant_id');
    }
}

$emailService = new EmailService();
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
        // Get card path if available
        $cardPath = null;
        if (!empty($participant['card_output_path'])) {
            // Handle both absolute and relative paths
            $path = $participant['card_output_path'];
            if (!file_exists($path)) {
                $path = ROOT_PATH . '/' . $participant['card_output_path'];
            }
            if (file_exists($path)) {
                $cardPath = $path;
            }
        }
        
        $result = $emailService->sendInvitationEmail($participant, $event, $cardPath);
        
        if ($result['success']) {
            $sent++;
            
            // Log successful email
            $db->execute("
                INSERT INTO email_logs (participant_id, event_id, email, message_type, status, sent_by)
                VALUES (:participant_id, :event_id, :email, 'invitation', 'sent', :sent_by)
            ", [
                'participant_id' => $participant['id'],
                'event_id' => $eventId,
                'email' => $participant['email'],
                'sent_by' => $userId
            ]);
        } else {
            $failed++;
            $errors[] = $participant['email'] . ': ' . $result['message'];
            
            // Log failed email
            $db->execute("
                INSERT INTO email_logs (participant_id, event_id, email, message_type, status, error_message, sent_by)
                VALUES (:participant_id, :event_id, :email, 'invitation', 'failed', :error_message, :sent_by)
            ", [
                'participant_id' => $participant['id'],
                'event_id' => $eventId,
                'email' => $participant['email'],
                'error_message' => $result['message'] ?? 'Unknown error',
                'sent_by' => $userId
            ]);
        }
        
        // Small delay to prevent rate limiting
        usleep(100000); // 0.1 second delay
        
    } catch (Exception $e) {
        $failed++;
        $errors[] = $participant['email'] . ': ' . $e->getMessage();
        
        // Log exception
        $db->execute("
            INSERT INTO email_logs (participant_id, event_id, email, message_type, status, error_message, sent_by)
            VALUES (:participant_id, :event_id, :email, 'invitation', 'failed', :error_message, :sent_by)
        ", [
            'participant_id' => $participant['id'],
            'event_id' => $eventId,
            'email' => $participant['email'],
            'error_message' => $e->getMessage(),
            'sent_by' => $userId
        ]);
    }
}

$message = "Invitations sent: {$sent} successful";
if ($skipped > 0) {
    $message .= ", {$skipped} skipped (already sent)";
}
if ($failed > 0) {
    $message .= ", {$failed} failed";
}

$response = [
    'success' => true,
    'message' => $message,
    'sent' => $sent,
    'failed' => $failed,
    'skipped' => $skipped,
    'total' => count($participants)
];

if (!empty($errors) && $failed > 0) {
    $response['errors'] = array_slice($errors, 0, 5); // Return first 5 errors
}

echo json_encode($response);
