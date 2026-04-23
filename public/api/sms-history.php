<?php
/**
 * Get Participant SMS History API
 * Returns SMS sending history for a participant
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Database\Connection;

Session::start();

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$participantId = (int) ($_GET['participant_id'] ?? 0);

if ($participantId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid participant ID']);
    exit;
}

$db = Connection::getInstance();

// Get SMS history for this participant
$smsLogs = $db->query("
    SELECT sl.*, u.full_name as sent_by_name
    FROM sms_logs sl
    LEFT JOIN users u ON sl.sent_by = u.id
    WHERE sl.participant_id = :participant_id
    ORDER BY sl.sent_at DESC
", ['participant_id' => $participantId]);

// Get last successful SMS for invitation
$lastInvitation = $db->queryOne("
    SELECT * FROM sms_logs 
    WHERE participant_id = :participant_id 
    AND message_type = 'invitation'
    AND status = 'sent'
    ORDER BY sent_at DESC
    LIMIT 1
", ['participant_id' => $participantId]);

echo json_encode([
    'success' => true,
    'history' => $smsLogs,
    'invitation_sent' => $lastInvitation !== null,
    'last_invitation' => $lastInvitation
]);
