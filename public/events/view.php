<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/sms.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;
use App\Services\VerificationService;

Session::start();
Auth::require();

$db = Connection::getInstance();
$eventId = (int) ($_GET['id'] ?? 0);

if ($eventId <= 0) {
    Session::flash('error', 'Invalid event ID');
    Utils::redirect('/tukioqrcode/public/events/index.php');
}

$event = $db->queryOne("SELECT * FROM events WHERE id = :id", ['id' => $eventId]);

if (!$event) {
    Session::flash('error', 'Event not found');
    Utils::redirect('/tukioqrcode/public/events/index.php');
}

// Get statistics
$verificationService = new VerificationService();
$stats = $verificationService->getEventStats($eventId);

// Get SMS stats
$smsStats = $db->queryOne("
    SELECT 
        COUNT(DISTINCT CASE WHEN status = 'sent' AND message_type = 'invitation' THEN participant_id END) as sms_sent_count,
        COUNT(CASE WHEN status = 'sent' AND message_type = 'invitation' THEN 1 END) as total_sms_sent,
        (SELECT COUNT(*) FROM participants WHERE event_id = :event_id2 AND phone IS NOT NULL AND phone != '' AND status = 'active') as has_phone_count
    FROM sms_logs 
    WHERE event_id = :event_id
", ['event_id' => $eventId, 'event_id2' => $eventId]);

$smsSentCount = $smsStats['sms_sent_count'] ?? 0;
$hasPhoneCount = $smsStats['has_phone_count'] ?? 0;
$smsNotSentCount = $hasPhoneCount - $smsSentCount;

// Get Email stats
$emailStats = $db->queryOne("
    SELECT 
        COUNT(DISTINCT CASE WHEN status = 'sent' AND message_type = 'invitation' THEN participant_id END) as email_sent_count,
        COUNT(CASE WHEN status = 'sent' AND message_type = 'invitation' THEN 1 END) as total_email_sent,
        (SELECT COUNT(*) FROM participants WHERE event_id = :event_id2 AND email IS NOT NULL AND email != '' AND status = 'active') as has_email_count
    FROM email_logs 
    WHERE event_id = :event_id
", ['event_id' => $eventId, 'event_id2' => $eventId]);

$emailSentCount = $emailStats['email_sent_count'] ?? 0;
$hasEmailCount = $emailStats['has_email_count'] ?? 0;
$emailNotSentCount = $hasEmailCount - $emailSentCount;

// Get WhatsApp stats
$whatsappStats = $db->queryOne("
    SELECT 
        COUNT(DISTINCT CASE WHEN message_type = 'invitation' THEN participant_id END) as whatsapp_shared_count,
        COUNT(CASE WHEN message_type = 'invitation' THEN 1 END) as total_whatsapp_shares
    FROM whatsapp_logs 
    WHERE event_id = :event_id
", ['event_id' => $eventId]);

$whatsappSharedCount = $whatsappStats['whatsapp_shared_count'] ?? 0;
$whatsappNotSharedCount = $hasPhoneCount - $whatsappSharedCount;

// Get batches
$batches = $db->query("
    SELECT * FROM batches
    WHERE event_id = :event_id
    ORDER BY created_at DESC
", ['event_id' => $eventId]);

// Get recent participants
$participants = $db->query("
    SELECT * FROM participants
    WHERE event_id = :event_id
    ORDER BY created_at DESC
    LIMIT 10
", ['event_id' => $eventId]);

// Get all participants with phone numbers (for SMS participant picker)
$smsParticipants = $db->query("
    SELECT p.id, p.name, p.phone, p.ticket_type, p.unique_id,
        (SELECT COUNT(*) FROM sms_logs sl WHERE sl.participant_id = p.id AND sl.message_type = 'invitation' AND sl.status = 'sent') as sms_sent
    FROM participants p
    WHERE p.event_id = :event_id
    AND p.phone IS NOT NULL AND p.phone != ''
    AND p.status = 'active'
    ORDER BY p.name ASC
", ['event_id' => $eventId]);

$pageTitle = $event['event_name'];

$basePath = Utils::basePath();
ob_start();
?>

<div class="stats-row">
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $stats['total_participants'] ?></h3>
            <p>Participants</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $stats['total_guests'] ?></h3>
            <p>Total Guests</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $stats['guests_checked_in'] ?></h3>
            <p>Checked In</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $stats['checkin_percentage'] ?>%</h3>
            <p>Check-in Rate</p>
        </div>
    </div>
</div>

<!-- Send Invitations Card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-paper-plane"></i> Send Invitations</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px;">
            
            <!-- Email Invitations -->
            <div style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-radius: 12px; padding: 20px; border: 1px solid #bfdbfe;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-envelope" style="color: white; font-size: 18px;"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; color: #1e40af;">Email Invitations</h4>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #3b82f6;">With invitation card download link</p>
                    </div>
                </div>
                
                <!-- Email Stats -->
                <div style="display: flex; gap: 12px; margin-bottom: 12px; font-size: 12px;">
                    <span style="background: #bfdbfe; color: #1e40af; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-check"></i> <?= $emailSentCount ?> sent
                    </span>
                    <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-clock"></i> <?= $emailNotSentCount ?> pending
                    </span>
                    <span style="background: #e5e7eb; color: #374151; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-at"></i> <?= $hasEmailCount ?> with email
                    </span>
                </div>
                
                <p style="font-size: 13px; color: #1e3a8a; margin-bottom: 12px;">
                    Send beautifully designed email invitations with event details and QR code download.
                </p>
                
                <!-- Resend checkbox -->
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #1e40af; margin-bottom: 12px; cursor: pointer;">
                    <input type="checkbox" id="emailForceResend" style="accent-color: #3b82f6;">
                    <span>Include already sent (resend to all)</span>
                </label>
                
                <button type="button" id="sendAllInvitations" class="btn btn-primary" style="width: 100%;" onclick="sendEmailInvitations(true)">
                    <i class="fas fa-paper-plane"></i> Send Email to All
                </button>
                <div id="emailStatus" style="margin-top: 12px; font-size: 13px; color: #1e3a8a;"></div>
            </div>
            
            <!-- SMS Invitations -->
            <div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-radius: 12px; padding: 20px; border: 1px solid #bbf7d0;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-sms" style="color: white; font-size: 18px;"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; color: #166534;">SMS Invitations</h4>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #22c55e;">Quick text message delivery</p>
                    </div>
                </div>
                
                <!-- SMS Stats -->
                <div style="display: flex; gap: 12px; margin-bottom: 12px; font-size: 12px;">
                    <span style="background: #bbf7d0; color: #166534; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-check"></i> <?= $smsSentCount ?> sent
                    </span>
                    <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-clock"></i> <?= $smsNotSentCount ?> pending
                    </span>
                    <span style="background: #e5e7eb; color: #374151; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-phone"></i> <?= $hasPhoneCount ?> with phone
                    </span>
                </div>
                
                <p style="font-size: 13px; color: #14532d; margin-bottom: 12px;">
                    Send SMS invitations with event details and ticket info directly to their phones.
                </p>
                
                <!-- SMS Message Preview/Edit -->
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 13px; color: #166534; font-weight: 500; display: block; margin-bottom: 6px;">
                        <i class="fas fa-edit"></i> Message Template:
                    </label>
                    <textarea id="smsMessageTemplate" style="width: 100%; padding: 10px 12px; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; min-height: 180px; resize: vertical; background: white; color: #14532d; font-family: inherit;" placeholder="Loading template..."></textarea>
                    <small style="color: #6b7280; font-size: 11px;">
                        <i class="fas fa-info-circle"></i> Placeholders: <code>{name}</code>, <code>{ticket_type}</code>, <code>{unique_id}</code>, <code>{total_guests}</code> — will be replaced per participant.
                    </small>
                </div>
                
                <!-- Resend checkbox -->
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #166534; margin-bottom: 12px; cursor: pointer;">
                    <input type="checkbox" id="smsForceResend" style="accent-color: #22c55e;">
                    <span>Include already sent (resend to all)</span>
                </label>
                
                <!-- Participant Selection -->
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 13px; color: #166534; font-weight: 500; display: block; margin-bottom: 6px;">
                        <i class="fas fa-users"></i> Select Recipients:
                    </label>
                    <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                        <button type="button" class="btn" style="flex: 1; background: #22c55e; color: white; font-size: 12px; padding: 6px 10px;" onclick="smsSelectAll()">
                            <i class="fas fa-check-double"></i> Select All
                        </button>
                        <button type="button" class="btn" style="flex: 1; background: #e5e7eb; color: #374151; font-size: 12px; padding: 6px 10px;" onclick="smsDeselectAll()">
                            <i class="fas fa-times"></i> Deselect All
                        </button>
                        <button type="button" class="btn" style="flex: 1; background: #fef3c7; color: #92400e; font-size: 12px; padding: 6px 10px;" onclick="smsSelectUnsent()">
                            <i class="fas fa-clock"></i> Unsent Only
                        </button>
                    </div>
                    <div style="position: relative; margin-bottom: 8px;">
                        <input type="text" id="smsParticipantSearch" placeholder="Search participants..." style="width: 100%; padding: 8px 12px 8px 32px; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px;">
                        <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 13px;"></i>
                    </div>
                    <div id="smsParticipantList" style="max-height: 200px; overflow-y: auto; border: 1px solid #bbf7d0; border-radius: 8px; background: white;">
                        <?php foreach ($smsParticipants as $sp): ?>
                        <label style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid #f0fdf4; cursor: pointer; font-size: 13px;" class="sms-participant-item" data-name="<?= strtolower(Utils::escape($sp['name'])) ?>" data-sent="<?= $sp['sms_sent'] > 0 ? '1' : '0' ?>">
                            <input type="checkbox" class="sms-participant-cb" value="<?= $sp['id'] ?>" checked style="accent-color: #22c55e;">
                            <span style="flex: 1; color: #14532d;"><?= Utils::escape($sp['name']) ?></span>
                            <span style="font-size: 11px; color: #6b7280;"><?= Utils::escape($sp['phone']) ?></span>
                            <?php if ($sp['sms_sent'] > 0): ?>
                            <span style="background: #bbf7d0; color: #166534; padding: 2px 6px; border-radius: 4px; font-size: 10px;"><i class="fas fa-check"></i> Sent</span>
                            <?php else: ?>
                            <span style="background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-size: 10px;"><i class="fas fa-clock"></i> Pending</span>
                            <?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($smsParticipants)): ?>
                        <p style="padding: 16px; text-align: center; color: #6b7280; margin: 0;">No participants with phone numbers.</p>
                        <?php endif; ?>
                    </div>
                    <small style="color: #6b7280; font-size: 11px; margin-top: 4px; display: block;">
                        <i class="fas fa-info-circle"></i> <span id="smsSelectedCount"><?= count($smsParticipants) ?></span> of <?= count($smsParticipants) ?> selected
                    </small>
                </div>
                
                <div style="display: flex; gap: 8px;">
                    <button type="button" id="sendSelectedSms" class="btn" style="flex: 1; background: #22c55e; color: white;" onclick="sendSmsInvitations(false)">
                        <i class="fas fa-sms"></i> Send to Selected
                    </button>
                    <button type="button" id="sendAllSms" class="btn" style="flex: 1; background: #166534; color: white;" onclick="sendSmsInvitations(true)">
                        <i class="fas fa-sms"></i> Send to All
                    </button>
                </div>
                <div id="smsStatus" style="margin-top: 12px; font-size: 13px; color: #14532d;"></div>
            </div>
            
            <!-- WhatsApp Sharing -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-radius: 12px; padding: 20px; border: 1px solid #a7f3d0;">
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #25d366 0%, #128c7e 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                        <i class="fab fa-whatsapp" style="color: white; font-size: 20px;"></i>
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 16px; color: #065f46;">WhatsApp Share</h4>
                        <p style="margin: 2px 0 0 0; font-size: 12px; color: #10b981;">Manual sharing (one by one)</p>
                    </div>
                </div>
                
                <!-- WhatsApp Stats -->
                <div style="display: flex; gap: 12px; margin-bottom: 12px; font-size: 12px; flex-wrap: wrap;">
                    <span style="background: #a7f3d0; color: #065f46; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-check"></i> <?= $whatsappSharedCount ?> shared
                    </span>
                    <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px;">
                        <i class="fas fa-clock"></i> <?= $whatsappNotSharedCount ?> pending
                    </span>
                    <span style="background: #e5e7eb; color: #374151; padding: 4px 8px; border-radius: 4px;">
                        <i class="fab fa-whatsapp"></i> <?= $hasPhoneCount ?> with phone
                    </span>
                </div>
                
                <p style="font-size: 13px; color: #064e3b; margin-bottom: 12px;">
                    Share invitations via WhatsApp individually. Each click opens WhatsApp - you send manually to avoid bans.
                </p>
                
                <div style="background: #d1fae5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 12px; margin-bottom: 12px;">
                    <p style="margin: 0; font-size: 12px; color: #065f46;">
                        <i class="fas fa-info-circle"></i> <strong>Note:</strong> WhatsApp sharing is done per participant from the edit participant page. 
                        This avoids automation and keeps your number safe from being banned.
                    </p>
                </div>
                
                <a href="<?= $basePath ?>/participants/index.php?event_id=<?= $eventId ?>" class="btn" style="width: 100%; background: #25d366; color: white; text-align: center;">
                    <i class="fab fa-whatsapp"></i> Go to Participants
                </a>
            </div>
            
        </div>
        
        <!-- Progress Bar (shared) -->
        <div id="invitationProgress" style="display: none; margin-top: 20px;">
            <div style="background: #e5e7eb; border-radius: 8px; height: 8px; overflow: hidden;">
                <div id="progressBar" style="background: #6366f1; height: 100%; width: 0%; transition: width 0.3s;"></div>
            </div>
            <p id="progressText" style="margin-top: 8px; font-size: 14px; color: #6b7280;"></p>
        </div>
    </div>
</div>

<!-- Send Thank You SMS Card -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-heart"></i> Tuma Shukrani</h3>
    </div>
    <div class="card-body">
        <div style="background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%); border-radius: 12px; padding: 20px; border: 1px solid #fbcfe8;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-heart" style="color: white; font-size: 18px;"></i>
                </div>
                <div>
                    <h4 style="margin: 0; font-size: 16px; color: #9d174d;">SMS ya Shukrani</h4>
                    <p style="margin: 2px 0 0 0; font-size: 12px; color: #ec4899;">Kwa waliohudhuria tukio</p>
                </div>
            </div>
            
            <!-- Attendee Stats -->
            <div style="display: flex; gap: 12px; margin-bottom: 12px; font-size: 12px;">
                <span style="background: #fbcfe8; color: #9d174d; padding: 4px 8px; border-radius: 4px;">
                    <i class="fas fa-user-check"></i> <?= $stats['guests_checked_in'] ?> wamehudhuria
                </span>
            </div>
            
            <p style="font-size: 13px; color: #831843; margin-bottom: 12px;">
                Tuma ujumbe wa shukrani kwa wote waliohudhuria tukio lako. Chagua template ya ujumbe.
            </p>
            
            <!-- Template Selection -->
            <div style="margin-bottom: 12px;">
                <label style="font-size: 13px; color: #9d174d; font-weight: 500; display: block; margin-bottom: 6px;">Chagua Template:</label>
                <select id="thanksTemplate" style="width: 100%; padding: 10px 12px; border: 1px solid #fbcfe8; border-radius: 8px; font-size: 14px; background: white; color: #831843;" onchange="previewTemplate()">
                    <option value="simple">Shukrani Fupi</option>
                    <option value="formal">Shukrani Rasmi</option>
                    <option value="warm">Shukrani ya Joto</option>
                    <option value="christmas">Shukrani ya Krismasi</option>
                    <option value="newyear">Shukrani ya Mwaka Mpya</option>
                    <option value="custom">Ujumbe Wako Mwenyewe</option>
                </select>
            </div>
            
            <!-- Template Preview -->
            <div id="templatePreview" style="background: white; border: 1px solid #fbcfe8; border-radius: 8px; padding: 12px; margin-bottom: 12px; font-size: 13px; color: #831843; white-space: pre-wrap; max-height: 150px; overflow-y: auto;"></div>
            
            <!-- Custom Message (hidden by default) -->
            <div id="customMessageDiv" style="display: none; margin-bottom: 12px;">
                <label style="font-size: 13px; color: #9d174d; font-weight: 500; display: block; margin-bottom: 6px;">Andika Ujumbe Wako:</label>
                <textarea id="customMessage" style="width: 100%; padding: 10px 12px; border: 1px solid #fbcfe8; border-radius: 8px; font-size: 14px; min-height: 100px; resize: vertical;" placeholder="Andika ujumbe wako hapa..."></textarea>
            </div>
            
            <!-- Resend checkbox -->
            <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #9d174d; margin-bottom: 12px; cursor: pointer;">
                <input type="checkbox" id="thanksForceResend" style="accent-color: #ec4899;">
                <span>Jumuisha waliotumwa tayari (tuma tena kwa wote)</span>
            </label>
            
            <button type="button" id="sendThanksBtn" class="btn" style="width: 100%; background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); color: white;" onclick="sendThanksSms()">
                <i class="fas fa-heart"></i> Tuma Shukrani kwa Wote
            </button>
            <div id="thanksStatus" style="margin-top: 12px; font-size: 13px; color: #9d174d;"></div>
        </div>
        
        <!-- Progress Bar for Thanks -->
        <div id="thanksProgress" style="display: none; margin-top: 20px;">
            <div style="background: #e5e7eb; border-radius: 8px; height: 8px; overflow: hidden;">
                <div id="thanksProgressBar" style="background: #ec4899; height: 100%; width: 0%; transition: width 0.3s;"></div>
            </div>
            <p id="thanksProgressText" style="margin-top: 8px; font-size: 14px; color: #6b7280;"></p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Event Details</h3>
        <?php if (Auth::isAdmin()): ?>
        <a href="edit.php?id=<?= $eventId ?>" class="btn btn-outline btn-sm">
            Edit
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div>
                <strong>Event Code:</strong><br>
                <code><?= Utils::escape($event['event_code']) ?></code>
            </div>
            <div>
                <strong>Date:</strong><br>
                <?= Utils::formatDate($event['event_date']) ?>
                <?= $event['event_time'] ? ' at ' . date('h:i A', strtotime($event['event_time'])) : '' ?>
            </div>
            <div>
                <strong>Venue:</strong><br>
                <?= Utils::escape($event['event_venue']) ?: 'TBD' ?>
            </div>
            <div>
                <strong>Status:</strong><br>
                <span class="badge badge-<?= $event['status'] ?>"><?= ucfirst($event['status']) ?></span>
            </div>
        </div>
        <?php if ($event['description']): ?>
        <div style="margin-top: 20px;">
            <strong>Description:</strong><br>
            <?= nl2br(Utils::escape($event['description'])) ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Batches</h3>
        <?php if (Auth::isAdmin()): ?>
        <a href="<?= $basePath ?>/batches/upload.php?event_id=<?= $eventId ?>" class="btn btn-primary btn-sm">
            Upload New Batch
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($batches)): ?>
            <p class="text-muted">No batches uploaded yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Batch Name</th>
                            <th>Cards</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= Utils::escape($batch['batch_name']) ?></td>
                            <td><?= $batch['processed'] ?> / <?= $batch['total_cards'] ?></td>
                            <td><span class="badge badge-<?= $batch['status'] ?>"><?= ucfirst($batch['status']) ?></span></td>
                            <td><?= Utils::formatDateTime($batch['created_at']) ?></td>
                            <td>
                                <?php if ($batch['status'] === 'completed'): ?>
                                <a href="<?= $basePath ?>/batches/download.php?id=<?= $batch['id'] ?>" class="btn btn-sm btn-outline">
                                    Download
                                </a>
                                <?php elseif ($batch['status'] === 'pending'): ?>
                                <a href="<?= $basePath ?>/batches/process.php?id=<?= $batch['id'] ?>" class="btn btn-sm btn-primary">
                                    Process
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Recent Participants</h3>
        <a href="<?= $basePath ?>/participants/index.php?event_id=<?= $eventId ?>" class="btn btn-outline btn-sm">
            View All
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($participants)): ?>
            <p class="text-muted">No participants yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Unique ID</th>
                            <th>Name</th>
                            <th>Ticket</th>
                            <th>Guests</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $p): ?>
                        <tr>
                            <td><code><?= Utils::escape($p['unique_id']) ?></code></td>
                            <td><?= Utils::escape($p['name']) ?></td>
                            <td><span class="badge badge-info"><?= Utils::escape($p['ticket_type']) ?></span></td>
                            <td><?= $p['guests_checked_in'] ?> / <?= $p['total_guests'] ?></td>
                            <td>
                                <?php if ($p['is_fully_checked_in']): ?>
                                    <span class="badge badge-success">Checked In</span>
                                <?php elseif ($p['guests_checked_in'] > 0): ?>
                                    <span class="badge badge-warning">Partial</span>
                                <?php else: ?>
                                    <span class="badge badge-draft">Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-icon warning" id="modalIcon">
                <i class="fas fa-paper-plane" id="modalIconInner"></i>
            </div>
            <h3 id="modalTitle">Send Invitations</h3>
        </div>
        <div class="modal-body">
            <p id="modalMessage">This will send invitations to all participants.</p>
            <p id="modalNote" style="margin-top: 12px; font-size: 13px; color: #6b7280;">
                <i class="fas fa-info-circle"></i> 
                <span id="modalNoteText">Participants without contact info will be skipped.</span>
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
            <button type="button" class="btn btn-primary" id="modalConfirmBtn" onclick="confirmSend()">
                <i class="fas fa-paper-plane"></i> Send
            </button>
        </div>
    </div>
</div>

<script>
const eventId = <?= $eventId ?>;
const basePath = '<?= $basePath ?>';
let pendingAction = null; // 'email' or 'sms'
let pendingSendAll = false;

// Pre-fill SMS invitation template
<?php
use App\Services\SmsService;
$smsServiceForTemplate = new SmsService();
$smsTemplate = $smsServiceForTemplate->getInvitationTemplate($event);
?>
document.addEventListener('DOMContentLoaded', function() {
    const smsTemplateEl = document.getElementById('smsMessageTemplate');
    if (smsTemplateEl) {
        smsTemplateEl.value = <?= json_encode($smsTemplate, JSON_UNESCAPED_UNICODE) ?>;
    }
    
    // SMS participant search filter
    const searchInput = document.getElementById('smsParticipantSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('.sms-participant-item').forEach(function(item) {
                const name = item.getAttribute('data-name');
                item.style.display = name.includes(query) ? '' : 'none';
            });
        });
    }
    
    // Update selected count when checkboxes change
    document.querySelectorAll('.sms-participant-cb').forEach(function(cb) {
        cb.addEventListener('change', updateSmsSelectedCount);
    });
});

function updateSmsSelectedCount() {
    const checked = document.querySelectorAll('.sms-participant-cb:checked').length;
    const el = document.getElementById('smsSelectedCount');
    if (el) el.textContent = checked;
}

function smsSelectAll() {
    document.querySelectorAll('.sms-participant-item').forEach(function(item) {
        if (item.style.display !== 'none') {
            item.querySelector('.sms-participant-cb').checked = true;
        }
    });
    updateSmsSelectedCount();
}

function smsDeselectAll() {
    document.querySelectorAll('.sms-participant-cb').forEach(function(cb) {
        cb.checked = false;
    });
    updateSmsSelectedCount();
}

function smsSelectUnsent() {
    document.querySelectorAll('.sms-participant-item').forEach(function(item) {
        const cb = item.querySelector('.sms-participant-cb');
        cb.checked = item.getAttribute('data-sent') === '0';
    });
    updateSmsSelectedCount();
}

function getSelectedParticipantIds() {
    return Array.from(document.querySelectorAll('.sms-participant-cb:checked')).map(function(cb) {
        return parseInt(cb.value);
    });
}

function showModal(type, sendAll) {
    pendingAction = type;
    pendingSendAll = sendAll;
    
    const modal = document.getElementById('confirmModal');
    const icon = document.getElementById('modalIcon');
    const iconInner = document.getElementById('modalIconInner');
    const title = document.getElementById('modalTitle');
    const message = document.getElementById('modalMessage');
    const noteText = document.getElementById('modalNoteText');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    
    if (type === 'email') {
        icon.className = 'modal-icon info';
        iconInner.className = 'fas fa-envelope';
        title.textContent = 'Send Email Invitations';
        message.textContent = 'This will send invitation emails to all participants with valid email addresses.';
        noteText.textContent = 'Participants without email addresses will be skipped.';
        confirmBtn.innerHTML = '<i class="fas fa-envelope"></i> Send Emails';
        confirmBtn.style.background = '';
    } else if (type === 'sms') {
        const selectedCount = sendAll ? <?= count($smsParticipants) ?> : getSelectedParticipantIds().length;
        if (!sendAll && selectedCount === 0) {
            document.getElementById('smsStatus').innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> Please select at least one participant.';
            return;
        }
        icon.className = 'modal-icon success';
        iconInner.className = 'fas fa-sms';
        title.textContent = 'Send SMS Invitations';
        message.textContent = sendAll 
            ? 'This will send SMS invitations to all participants with valid phone numbers.'
            : 'This will send SMS invitations to ' + selectedCount + ' selected participant(s).';
        noteText.textContent = 'Participants without phone numbers will be skipped.';
        confirmBtn.innerHTML = '<i class="fas fa-sms"></i> Send SMS';
        confirmBtn.style.background = '#22c55e';
    }
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('confirmModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on overlay click
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

function sendEmailInvitations(sendToAll = false) {
    showModal('email', sendToAll);
}

function sendSmsInvitations(sendToAll = false) {
    showModal('sms', sendToAll);
}

async function confirmSend() {
    closeModal();
    
    if (pendingAction === 'email') {
        await executeEmailSend();
    } else if (pendingAction === 'sms') {
        await executeSmsSend();
    }
}

async function executeEmailSend() {
    const btn = document.getElementById('sendAllInvitations');
    const status = document.getElementById('emailStatus');
    const progressDiv = document.getElementById('invitationProgress');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const forceResend = document.getElementById('emailForceResend')?.checked || false;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    status.innerHTML = '';
    progressDiv.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.style.background = '#3b82f6';
    progressText.innerHTML = 'Sending email invitations...';
    
    try {
        const response = await fetch(basePath + '/api/send-invitations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                event_id: eventId, 
                send_to_all: pendingSendAll,
                force_resend: forceResend
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            const totalProcessed = result.sent + result.failed;
            const percentage = totalProcessed > 0 ? Math.round((result.sent / totalProcessed) * 100) : 100;
            progressBar.style.width = percentage + '%';
            progressBar.style.background = result.failed > 0 ? '#f59e0b' : '#10b981';
            status.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> ' + result.message;
            
            let progressInfo = `Emails: ${result.sent} sent`;
            if (result.skipped > 0) {
                progressInfo += `, ${result.skipped} skipped`;
            }
            if (result.failed > 0) {
                progressInfo += `, ${result.failed} failed`;
            }
            progressInfo += ` out of ${result.total}`;
            progressText.innerHTML = progressInfo;
            
            // Reload page after 2 seconds to update stats
            setTimeout(() => location.reload(), 2000);
        } else {
            status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> ' + result.message;
            progressDiv.style.display = 'none';
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> Failed to send emails.';
        progressDiv.style.display = 'none';
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email to All';
}

async function executeSmsSend() {
    const btn = pendingSendAll ? document.getElementById('sendAllSms') : document.getElementById('sendSelectedSms');
    const status = document.getElementById('smsStatus');
    const progressDiv = document.getElementById('invitationProgress');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const forceResend = document.getElementById('smsForceResend')?.checked || false;
    const customMessage = document.getElementById('smsMessageTemplate')?.value || '';
    
    const payload = {
        event_id: eventId,
        send_to_all: pendingSendAll,
        force_resend: forceResend,
        custom_message: customMessage.trim() || null
    };
    
    if (!pendingSendAll) {
        payload.participant_ids = getSelectedParticipantIds();
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    status.innerHTML = '';
    progressDiv.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.style.background = '#22c55e';
    progressText.innerHTML = 'Sending SMS invitations...';
    
    try {
        const response = await fetch(basePath + '/api/send-sms-invitations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        
        const result = await response.json();
        
        if (result.success) {
            const totalProcessed = result.sent + result.failed;
            const percentage = totalProcessed > 0 ? Math.round((result.sent / totalProcessed) * 100) : 100;
            progressBar.style.width = percentage + '%';
            progressBar.style.background = result.failed > 0 ? '#f59e0b' : '#10b981';
            
            let msg = result.message;
            if (result.test_mode) {
                msg += ' (Test Mode)';
            }
            status.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> ' + msg;
            
            let progressInfo = `SMS: ${result.sent} sent`;
            if (result.skipped > 0) {
                progressInfo += `, ${result.skipped} skipped`;
            }
            if (result.failed > 0) {
                progressInfo += `, ${result.failed} failed`;
            }
            progressInfo += ` out of ${result.total}`;
            progressText.innerHTML = progressInfo;
            
            // Reload page after 2 seconds to update stats
            setTimeout(() => location.reload(), 2000);
        } else {
            status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> ' + result.message;
            progressDiv.style.display = 'none';
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> Failed to send SMS.';
        progressDiv.style.display = 'none';
    }
    
    btn.disabled = false;
    btn.innerHTML = pendingSendAll 
        ? '<i class="fas fa-sms"></i> Send to All' 
        : '<i class="fas fa-sms"></i> Send to Selected';
}

// Template definitions
const thanksTemplates = {
    simple: `Asante sana kwa kuhudhuria tukio letu!\n\nTunathamini ushiriki wako.\n\nKaribu tena!\n\n©Tukio Langu App`,
    formal: `Ndugu Mgeni,\n\nTunashukuru sana kwa heshima yako ya kuhudhuria tukio letu.\n\nUshiriki wako ulikuwa muhimu sana kwetu na tunatumaini tukio lilikuwa la manufaa kwako.\n\nTunakaribisha tena siku zijazo.\n\nHeshima,\n©Tukio Langu App`,
    warm: `Asante kutoka moyoni!\n\nUwepo wako ulitufanya siku iwe ya kipekee. Tulifurahi sana kukuona na kushiriki wakati mzuri pamoja.\n\nTunatumaini ulifurahia kama sisi tulivyofurahia.\n\nKwa upendo,\n©Tukio Langu App`,
    christmas: `Krismasi Njema! 🎄\n\nAsante sana kwa kuhudhuria tukio letu wakati huu wa sikukuu!\n\nTunakutakia Krismasi yenye furaha na baraka nyingi.\n\nMwaka Mpya Mwema!\n\n©Tukio Langu App`,
    newyear: `Heri ya Mwaka Mpya! 🎉\n\nAsante kwa kuhudhuria tukio letu!\n\nTunakutakia mwaka mpya wenye afya njema, furaha, na mafanikio.\n\nKaribu tena mwaka huu mpya!\n\n©Tukio Langu App`,
    custom: ''
};

function previewTemplate() {
    const template = document.getElementById('thanksTemplate').value;
    const preview = document.getElementById('templatePreview');
    const customDiv = document.getElementById('customMessageDiv');
    
    if (template === 'custom') {
        customDiv.style.display = 'block';
        preview.textContent = document.getElementById('customMessage').value || 'Andika ujumbe wako hapo juu...';
    } else {
        customDiv.style.display = 'none';
        preview.textContent = thanksTemplates[template];
    }
}

// Update preview when custom message changes
document.addEventListener('DOMContentLoaded', function() {
    const customInput = document.getElementById('customMessage');
    if (customInput) {
        customInput.addEventListener('input', function() {
            if (document.getElementById('thanksTemplate').value === 'custom') {
                document.getElementById('templatePreview').textContent = this.value || 'Andika ujumbe wako hapo juu...';
            }
        });
    }
    // Initialize preview
    previewTemplate();
});

async function sendThanksSms() {
    const btn = document.getElementById('sendThanksBtn');
    const status = document.getElementById('thanksStatus');
    const progressDiv = document.getElementById('thanksProgress');
    const progressBar = document.getElementById('thanksProgressBar');
    const progressText = document.getElementById('thanksProgressText');
    const forceResend = document.getElementById('thanksForceResend')?.checked || false;
    const template = document.getElementById('thanksTemplate').value;
    const customMessage = document.getElementById('customMessage')?.value || '';
    
    // Get the message to send
    let messageToSend = template === 'custom' ? customMessage : thanksTemplates[template];
    
    if (!messageToSend.trim()) {
        status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> Tafadhali andika ujumbe wako.';
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Inatuma...';
    status.innerHTML = '';
    progressDiv.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.style.background = '#ec4899';
    progressText.innerHTML = 'Inatuma SMS za shukrani...';
    
    try {
        const response = await fetch(basePath + '/api/send-thanks-sms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                event_id: eventId, 
                force_resend: forceResend,
                template: template,
                custom_message: messageToSend
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            const totalProcessed = result.sent + result.failed;
            const percentage = totalProcessed > 0 ? Math.round((result.sent / totalProcessed) * 100) : 100;
            progressBar.style.width = percentage + '%';
            progressBar.style.background = result.failed > 0 ? '#f59e0b' : '#10b981';
            
            let msg = result.message;
            if (result.test_mode) {
                msg += ' (Hali ya Majaribio)';
            }
            status.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> ' + msg;
            
            let progressInfo = `SMS: ${result.sent} zimetumwa`;
            if (result.skipped > 0) {
                progressInfo += `, ${result.skipped} zilizoruwa`;
            }
            if (result.failed > 0) {
                progressInfo += `, ${result.failed} zilizoshindwa`;
            }
            progressInfo += ` kati ya ${result.total}`;
            progressText.innerHTML = progressInfo;
            
            // Reload page after 2 seconds to update stats
            setTimeout(() => location.reload(), 2000);
        } else {
            status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> ' + result.message;
            progressDiv.style.display = 'none';
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #dc2626;"></i> Imeshindwa kutuma SMS.';
        progressDiv.style.display = 'none';
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-heart"></i> Tuma Shukrani kwa Wote';
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
