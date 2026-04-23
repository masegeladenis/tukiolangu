<?php
/**
 * Send Thank You SMS to All Attendees API
 * Sends a simple thank you message (in Swahili) to all participants who checked in
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
$forceResend = (bool) ($input['force_resend'] ?? false);
$template = $input['template'] ?? 'simple';
$customMessage = $input['custom_message'] ?? null;

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

// Get all attendees who have checked in (guests_checked_in > 0)
$participants = $db->query("
    SELECT * FROM participants 
    WHERE event_id = :event_id 
    AND phone IS NOT NULL 
    AND phone != ''
    AND status = 'active'
    AND guests_checked_in > 0
", ['event_id' => $eventId]);

if (empty($participants)) {
    echo json_encode(['success' => false, 'message' => 'Hakuna waliohudhuria wenye nambari za simu']);
    exit;
}

// Check for already sent thank you messages (unless force resend)
$skipAlreadySent = !$forceResend;
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
            SELECT DISTINCT participant_id FROM sms_logs 
            WHERE participant_id IN (" . implode(',', $placeholders) . ")
            AND message_type = 'thank_you'
            AND status = 'sent'
        ", $params);
        
        $alreadySentIds = array_column($alreadySent, 'participant_id');
    }
}

$userId = Auth::user()['id'] ?? null;
$smsService = new SmsService();

// Build simple thank you message in Swahili (no personalization)
$eventName = $event['event_name'] ?? 'Tukio';
$eventDate = !empty($event['event_date']) 
    ? date('d/m/Y', strtotime($event['event_date'])) 
    : '';

// Define message templates
$templates = [
    'simple' => "Asante sana kwa kuhudhuria tukio letu!\n\nTunathamini ushiriki wako.\n\nKaribu tena!\n\n©Tukio Langu App",
    'formal' => "Ndugu Mgeni,\n\nTunashukuru sana kwa heshima yako ya kuhudhuria tukio letu.\n\nUshiriki wako ulikuwa muhimu sana kwetu na tunatumaini tukio lilikuwa la manufaa kwako.\n\nTunakaribisha tena siku zijazo.\n\nHeshima,\n©Tukio Langu App",
    'warm' => "Asante kutoka moyoni!\n\nUwepo wako ulitufanya siku iwe ya kipekee. Tulifurahi sana kukuona na kushiriki wakati mzuri pamoja.\n\nTunatumaini ulifurahia kama sisi tulivyofurahia.\n\nKwa upendo,\n©Tukio Langu App",
    'christmas' => "Krismasi Njema! 🎄\n\nAsante sana kwa kuhudhuria tukio letu wakati huu wa sikukuu!\n\nTunakutakia Krismasi yenye furaha na baraka nyingi.\n\nMwaka Mpya Mwema!\n\n©Tukio Langu App",
    'newyear' => "Heri ya Mwaka Mpya! 🎉\n\nAsante kwa kuhudhuria tukio letu!\n\nTunakutakia mwaka mpya wenye afya njema, furaha, na mafanikio.\n\nKaribu tena mwaka huu mpya!\n\n©Tukio Langu App"
];

// Use custom message if provided, otherwise use template
if (!empty($customMessage)) {
    $thankYouMessage = $customMessage;
} elseif (isset($templates[$template])) {
    $thankYouMessage = $templates[$template];
} else {
    $thankYouMessage = $templates['simple'];
}

$sent = 0;
$failed = 0;
$skipped = 0;
$errors = [];

foreach ($participants as $participant) {
    // Skip if already sent
    if (in_array($participant['id'], $alreadySentIds)) {
        $skipped++;
        continue;
    }
    
    try {
        $result = $smsService->send($participant['phone'], $thankYouMessage);
        
        if ($result['success']) {
            $sent++;
            
            // Log the SMS
            $db->query("
                INSERT INTO sms_logs (event_id, participant_id, phone, message_type, message, status, sent_by, sent_at)
                VALUES (:event_id, :participant_id, :phone, 'thank_you', :message, 'sent', :sent_by, NOW())
            ", [
                'event_id' => $eventId,
                'participant_id' => $participant['id'],
                'phone' => $participant['phone'],
                'message' => $thankYouMessage,
                'sent_by' => $userId
            ]);
        } else {
            $failed++;
            $errors[] = $participant['phone'] . ': ' . ($result['message'] ?? 'Failed');
            
            // Log the failed attempt
            $db->query("
                INSERT INTO sms_logs (event_id, participant_id, phone, message_type, message, status, error_message, sent_by, sent_at)
                VALUES (:event_id, :participant_id, :phone, 'thank_you', :message, 'failed', :error, :sent_by, NOW())
            ", [
                'event_id' => $eventId,
                'participant_id' => $participant['id'],
                'phone' => $participant['phone'],
                'message' => $thankYouMessage,
                'error' => $result['message'] ?? 'Failed',
                'sent_by' => $userId
            ]);
        }
    } catch (Exception $e) {
        $failed++;
        $errors[] = $participant['phone'] . ': ' . $e->getMessage();
    }
}

$total = count($participants);
$message = "SMS za shukrani zimetumwa: {$sent}";
if ($skipped > 0) {
    $message .= ", zilizorukwa: {$skipped}";
}
if ($failed > 0) {
    $message .= ", zilizoshindwa: {$failed}";
}
$message .= " kati ya {$total}";

echo json_encode([
    'success' => true,
    'message' => $message,
    'sent' => $sent,
    'failed' => $failed,
    'skipped' => $skipped,
    'total' => $total,
    'test_mode' => $smsService->isTestMode(),
    'errors' => $errors
]);
