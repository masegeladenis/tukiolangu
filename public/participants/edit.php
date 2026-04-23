<?php
/**
 * Edit Participant Page
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/sms.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::requirePermission('participants_manage');

$db = Connection::getInstance();
$participantId = (int) ($_GET['id'] ?? 0);

if ($participantId <= 0) {
    Session::flash('error', 'Invalid participant ID');
    Utils::redirect('/tukioqrcode/public/participants/index.php');
}

// Get participant with event info
$participant = $db->queryOne("
    SELECT p.*, e.event_name, e.event_code
    FROM participants p
    JOIN events e ON p.event_id = e.id
    WHERE p.id = :id
", ['id' => $participantId]);

if (!$participant) {
    Session::flash('error', 'Participant not found');
    Utils::redirect('/tukioqrcode/public/participants/index.php');
}

// Ensure current user can access this participant's event
if (!Auth::canAccessEvent((int) $participant['event_id'])) {
    Session::flash('error', 'Access denied.');
    Utils::redirect('/tukioqrcode/public/participants/index.php');
}

// Get SMS history
$smsLogs = $db->query("
    SELECT sl.*, u.full_name as sent_by_name
    FROM sms_logs sl
    LEFT JOIN users u ON sl.sent_by = u.id
    WHERE sl.participant_id = :participant_id
    ORDER BY sl.sent_at DESC
    LIMIT 5
", ['participant_id' => $participantId]);

// Get last invitation SMS
$lastInvitationSms = $db->queryOne("
    SELECT * FROM sms_logs 
    WHERE participant_id = :participant_id 
    AND message_type = 'invitation'
    AND status = 'sent'
    ORDER BY sent_at DESC
    LIMIT 1
", ['participant_id' => $participantId]);

// Get Email history
$emailLogs = $db->query("
    SELECT el.*, u.full_name as sent_by_name
    FROM email_logs el
    LEFT JOIN users u ON el.sent_by = u.id
    WHERE el.participant_id = :participant_id
    ORDER BY el.sent_at DESC
    LIMIT 5
", ['participant_id' => $participantId]);

// Get last invitation Email
$lastInvitationEmail = $db->queryOne("
    SELECT * FROM email_logs 
    WHERE participant_id = :participant_id 
    AND message_type = 'invitation'
    AND status = 'sent'
    ORDER BY sent_at DESC
    LIMIT 1
", ['participant_id' => $participantId]);

// Get WhatsApp share history
$whatsappLogs = $db->query("
    SELECT wl.*, u.full_name as shared_by_name
    FROM whatsapp_logs wl
    LEFT JOIN users u ON wl.shared_by = u.id
    WHERE wl.participant_id = :participant_id
    ORDER BY wl.shared_at DESC
    LIMIT 5
", ['participant_id' => $participantId]);

// Get last WhatsApp share
$lastWhatsappShare = $db->queryOne("
    SELECT * FROM whatsapp_logs 
    WHERE participant_id = :participant_id 
    AND message_type = 'invitation'
    ORDER BY shared_at DESC
    LIMIT 1
", ['participant_id' => $participantId]);

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = Utils::formatPhoneNumber($_POST['phone'] ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $ticketType = trim($_POST['ticket_type'] ?? '');
    $totalGuests = (int) ($_POST['total_guests'] ?? 1);
    $status = $_POST['status'] ?? 'active';
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if (empty($name)) {
        $error = 'Name is required';
    } elseif ($totalGuests < 1) {
        $error = 'Total guests must be at least 1';
    } elseif ($totalGuests < $participant['guests_checked_in']) {
        $error = 'Total guests cannot be less than already checked in (' . $participant['guests_checked_in'] . ')';
    } else {
        // Calculate guests remaining
        $guestsRemaining = $totalGuests - $participant['guests_checked_in'];
        $isFullyCheckedIn = ($guestsRemaining <= 0) ? 1 : 0;
        
        // Update participant
        $db->execute("
            UPDATE participants SET
                name = :name,
                email = :email,
                phone = :phone,
                organization = :organization,
                ticket_type = :ticket_type,
                total_guests = :total_guests,
                guests_remaining = :guests_remaining,
                is_fully_checked_in = :is_fully_checked_in,
                status = :status,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ", [
            'name' => $name,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'organization' => $organization ?: null,
            'ticket_type' => $ticketType,
            'total_guests' => $totalGuests,
            'guests_remaining' => $guestsRemaining,
            'is_fully_checked_in' => $isFullyCheckedIn,
            'status' => $status,
            'notes' => $notes ?: null,
            'id' => $participantId
        ]);
        
        $success = 'Participant updated successfully!';
        
        // Refresh participant data
        $participant = $db->queryOne("
            SELECT p.*, e.event_name, e.event_code
            FROM participants p
            JOIN events e ON p.event_id = e.id
            WHERE p.id = :id
        ", ['id' => $participantId]);
    }
}

$pageTitle = 'Edit Participant';
$basePath = Utils::basePath();

ob_start();
?>

<div class="page-header">
    <div>
        <h2>Edit Participant</h2>
        <p class="text-muted">Update participant details for <?= Utils::escape($participant['event_name']) ?></p>
    </div>
    <a href="<?= $basePath ?>/participants/index.php?event_id=<?= $participant['event_id'] ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Participants
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-user-edit"></i> Participant Details</h3>
        <span class="badge badge-info"><?= Utils::escape($participant['unique_id']) ?></span>
    </div>
    <div class="card-body">
        <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= Utils::escape($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= Utils::escape($success) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <?= Utils::csrfField() ?>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <!-- Personal Information -->
                <div class="form-section">
                    <h4 style="margin-bottom: 16px; color: #374151; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i class="fas fa-user"></i> Personal Information
                    </h4>
                    
                    <div class="form-group">
                        <label for="name">Full Name <span style="color: #dc2626;">*</span></label>
                        <input 
                            type="text" 
                            id="name" 
                            name="name" 
                            class="form-control"
                            value="<?= Utils::escape($participant['name']) ?>"
                            required
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control"
                            value="<?= Utils::escape($participant['email'] ?? '') ?>"
                            placeholder="email@example.com"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input 
                            type="text" 
                            id="phone" 
                            name="phone" 
                            class="form-control"
                            value="<?= Utils::escape($participant['phone'] ?? '') ?>"
                            placeholder="+254 xxx xxx xxx"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="organization">Organization</label>
                        <input 
                            type="text" 
                            id="organization" 
                            name="organization" 
                            class="form-control"
                            value="<?= Utils::escape($participant['organization'] ?? '') ?>"
                            placeholder="Company or Organization"
                        >
                    </div>
                </div>
                
                <!-- Ticket Information -->
                <div class="form-section">
                    <h4 style="margin-bottom: 16px; color: #374151; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i class="fas fa-ticket-alt"></i> Ticket Information
                    </h4>
                    
                    <div class="form-group">
                        <label for="ticket_type">Ticket Type <span style="color: #dc2626;">*</span></label>
                        <input 
                            type="text" 
                            id="ticket_type" 
                            name="ticket_type" 
                            class="form-control"
                            value="<?= Utils::escape($participant['ticket_type']) ?>"
                            required
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="total_guests">Total Guests Allowed <span style="color: #dc2626;">*</span></label>
                        <input 
                            type="number" 
                            id="total_guests" 
                            name="total_guests" 
                            class="form-control"
                            value="<?= $participant['total_guests'] ?>"
                            min="<?= $participant['guests_checked_in'] ?>"
                            required
                        >
                        <small class="text-muted">
                            Currently checked in: <?= $participant['guests_checked_in'] ?> guest(s)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="form-control">
                            <option value="active" <?= $participant['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="cancelled" <?= $participant['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            <option value="revoked" <?= $participant['status'] === 'revoked' ? 'selected' : '' ?>>Revoked</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea 
                            id="notes" 
                            name="notes" 
                            class="form-control"
                            rows="3"
                            placeholder="Any additional notes..."
                        ><?= Utils::escape($participant['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Check-in Status (Read-only) -->
            <div style="background: #f9fafb; border-radius: 8px; padding: 20px; margin-top: 24px; margin-bottom: 24px;">
                <h4 style="margin-bottom: 16px; color: #374151; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">
                    <i class="fas fa-clipboard-check"></i> Check-in Status
                </h4>
                <div style="display: flex; gap: 40px; flex-wrap: wrap;">
                    <div>
                        <p style="margin: 0; color: #6b7280; font-size: 13px;">Check-in Status</p>
                        <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                            <?php if ($participant['is_fully_checked_in']): ?>
                                <span style="color: #10b981;"><i class="fas fa-check-circle"></i> Fully Checked In</span>
                            <?php elseif ($participant['guests_checked_in'] > 0): ?>
                                <span style="color: #f59e0b;"><i class="fas fa-clock"></i> Partially Checked In</span>
                            <?php else: ?>
                                <span style="color: #6b7280;"><i class="fas fa-hourglass"></i> Pending</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p style="margin: 0; color: #6b7280; font-size: 13px;">Guests Checked In</p>
                        <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                            <?= $participant['guests_checked_in'] ?> / <?= $participant['total_guests'] ?>
                        </p>
                    </div>
                    <?php if ($participant['first_checkin_at']): ?>
                    <div>
                        <p style="margin: 0; color: #6b7280; font-size: 13px;">First Check-in</p>
                        <p style="margin: 4px 0 0 0; font-size: 14px; font-weight: 500;">
                            <?= Utils::formatDateTime($participant['first_checkin_at']) ?>
                        </p>
                    </div>
                    <?php endif; ?>
                    <?php if ($participant['last_checkin_at']): ?>
                    <div>
                        <p style="margin: 0; color: #6b7280; font-size: 13px;">Last Check-in</p>
                        <p style="margin: 4px 0 0 0; font-size: 14px; font-weight: 500;">
                            <?= Utils::formatDateTime($participant['last_checkin_at']) ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Check-in Action Buttons -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; display: flex; gap: 12px; flex-wrap: wrap;">
                    <?php if (!$participant['is_fully_checked_in'] && $participant['status'] !== 'cancelled' && $participant['status'] !== 'revoked'): ?>
                    <button type="button" class="btn btn-success" onclick="openCheckinModal()">
                        <i class="fas fa-check"></i> Check In Guest(s)
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($participant['guests_checked_in'] > 0): ?>
                    <button type="button" class="btn" style="background: #f59e0b; color: white;" onclick="openResetCheckinModal()">
                        <i class="fas fa-undo"></i> Reset Check-in
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($participant['is_fully_checked_in']): ?>
                    <span style="color: #10b981; font-weight: 500; display: flex; align-items: center;">
                        <i class="fas fa-check-circle" style="margin-right: 6px;"></i> All guests have been checked in
                    </span>
                    <?php elseif ($participant['status'] === 'cancelled' || $participant['status'] === 'revoked'): ?>
                    <span style="color: #dc2626; font-weight: 500; display: flex; align-items: center;">
                        <i class="fas fa-ban" style="margin-right: 6px;"></i> Check-in disabled - participant is <?= $participant['status'] ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <a href="<?= $basePath ?>/participants/index.php?event_id=<?= $participant['event_id'] ?>" class="btn btn-outline">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Invitation Card Section -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header" style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%);">
        <h3 style="color: #7c3aed;"><i class="fas fa-id-card"></i> Invitation Card</h3>
        <?php if (!empty($participant['card_output_path']) && (file_exists($participant['card_output_path']) || file_exists(ROOT_PATH . '/' . $participant['card_output_path']))): ?>
        <span class="badge" style="background: #7c3aed; color: white;">
            <i class="fas fa-check"></i> Generated
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php 
        $cardPath = $participant['card_output_path'] ?? '';
        $cardExists = false;
        $cardFullPath = '';
        
        if (!empty($cardPath)) {
            if (file_exists($cardPath)) {
                $cardExists = true;
                $cardFullPath = $cardPath;
            } elseif (file_exists(ROOT_PATH . '/' . $cardPath)) {
                $cardExists = true;
                $cardFullPath = ROOT_PATH . '/' . $cardPath;
            }
        }
        ?>
        
        <?php if ($cardExists): ?>
        <div>
            <div style="background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 16px; display: inline-block;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">Card Status</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600; color: #10b981;">
                    <i class="fas fa-check-circle"></i> Card Available
                </p>
            </div>
            
            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                <a href="<?= $basePath ?>/api/download-card.php?id=<?= urlencode($participant['unique_id']) ?>" 
                   class="btn" style="background: #7c3aed; color: white;">
                    <i class="fas fa-download"></i> Download Card
                </a>
                <button type="button" class="btn btn-outline" style="color: #7c3aed; border-color: #7c3aed;" onclick="copyCardLink()">
                    <i class="fas fa-link"></i> Copy Link
                </button>
                <span id="copyStatus" style="font-size: 13px;"></span>
            </div>
            
            <div style="margin-top: 16px; padding: 12px; background: #faf5ff; border: 1px solid #e9d5ff; border-radius: 8px;">
                <p style="margin: 0; font-size: 12px; color: #7c3aed;">
                    <i class="fas fa-info-circle"></i> <strong>Download Link:</strong><br>
                    <code style="font-size: 11px; word-break: break-all;" id="cardDownloadLink"><?= APP_URL ?>/public/api/download-card.php?id=<?= urlencode($participant['unique_id']) ?></code>
                </p>
            </div>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #6b7280;">
            <i class="fas fa-id-card" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
            <p style="margin: 0 0 8px 0; font-weight: 600;">No Card Generated</p>
            <p style="margin: 0; font-size: 14px;">
                The invitation card for this participant has not been generated yet.<br>
                Cards are created during batch processing.
            </p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Email Communication Section -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header" style="background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);">
        <h3 style="color: #1e40af;"><i class="fas fa-envelope"></i> Email Communication</h3>
        <?php if ($lastInvitationEmail): ?>
        <span class="badge badge-primary">
            <i class="fas fa-check"></i> Email Sent
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- Email Status -->
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 24px;">
            <div style="flex: 1; min-width: 200px; background: #f9fafb; padding: 16px; border-radius: 8px;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">Email Address</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                    <?php if (!empty($participant['email'])): ?>
                        <i class="fas fa-envelope" style="color: #3b82f6;"></i>
                        <?= Utils::escape($participant['email']) ?>
                    <?php else: ?>
                        <span style="color: #dc2626;"><i class="fas fa-times-circle"></i> No email address</span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="flex: 1; min-width: 200px; background: #f9fafb; padding: 16px; border-radius: 8px;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">Invitation Email Status</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                    <?php if ($lastInvitationEmail): ?>
                        <span style="color: #10b981;">
                            <i class="fas fa-check-circle"></i> Sent on <?= date('M j, Y g:i A', strtotime($lastInvitationEmail['sent_at'])) ?>
                        </span>
                    <?php else: ?>
                        <span style="color: #6b7280;"><i class="fas fa-clock"></i> Not sent yet</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <!-- Email Actions -->
        <?php if (!empty($participant['email'])): ?>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <?php if (!$lastInvitationEmail): ?>
                <button type="button" class="btn btn-primary" onclick="sendEmail(false)">
                    <i class="fas fa-paper-plane"></i> Send Invitation Email
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline" style="color: #1e40af; border-color: #1e40af;" onclick="sendEmail(true)">
                    <i class="fas fa-redo"></i> Resend Invitation Email
                </button>
            <?php endif; ?>
            <span id="emailStatus" style="font-size: 14px;"></span>
        </div>
        
        <!-- Email History -->
        <?php if (!empty($emailLogs)): ?>
        <div style="margin-top: 24px;">
            <h5 style="color: #374151; font-size: 14px; margin-bottom: 12px;">
                <i class="fas fa-history"></i> Recent Email History
            </h5>
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Date</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Type</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Status</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Sent By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emailLogs as $log): ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?= date('M j, Y g:i A', strtotime($log['sent_at'])) ?>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <span class="badge badge-info"><?= ucfirst($log['message_type']) ?></span>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?php if ($log['status'] === 'sent'): ?>
                                    <span style="color: #10b981;"><i class="fas fa-check"></i> Sent</span>
                                <?php else: ?>
                                    <span style="color: #dc2626;"><i class="fas fa-times"></i> Failed</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?= Utils::escape($log['sent_by_name'] ?? 'System') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-muted">
            <i class="fas fa-info-circle"></i> Add an email address to send email invitations.
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- SMS Communication Section -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header" style="background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%);">
        <h3 style="color: #16a34a;"><i class="fas fa-sms"></i> SMS Communication</h3>
        <?php if ($lastInvitationSms): ?>
        <span class="badge badge-success">
            <i class="fas fa-check"></i> SMS Sent
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- SMS Status -->
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 24px;">
            <div style="flex: 1; min-width: 200px; background: #f9fafb; padding: 16px; border-radius: 8px;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">Phone Number</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                    <?php if (!empty($participant['phone'])): ?>
                        <i class="fas fa-phone" style="color: #22c55e;"></i>
                        <?= Utils::escape($participant['phone']) ?>
                    <?php else: ?>
                        <span style="color: #dc2626;"><i class="fas fa-times-circle"></i> No phone number</span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="flex: 1; min-width: 200px; background: #f9fafb; padding: 16px; border-radius: 8px;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">Invitation SMS Status</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                    <?php if ($lastInvitationSms): ?>
                        <span style="color: #10b981;">
                            <i class="fas fa-check-circle"></i> Sent on <?= date('M j, Y g:i A', strtotime($lastInvitationSms['sent_at'])) ?>
                        </span>
                    <?php else: ?>
                        <span style="color: #6b7280;"><i class="fas fa-clock"></i> Not sent yet</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <!-- SMS Actions -->
        <?php if (!empty($participant['phone'])): ?>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <?php if (!$lastInvitationSms): ?>
                <button type="button" class="btn btn-success" onclick="sendSms(false)">
                    <i class="fas fa-paper-plane"></i> Send Invitation SMS
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline" style="color: #16a34a; border-color: #16a34a;" onclick="sendSms(true)">
                    <i class="fas fa-redo"></i> Resend Invitation SMS
                </button>
            <?php endif; ?>
            <span id="smsStatus" style="font-size: 14px;"></span>
        </div>
        
        <!-- SMS History -->
        <?php if (!empty($smsLogs)): ?>
        <div style="margin-top: 24px;">
            <h5 style="color: #374151; font-size: 14px; margin-bottom: 12px;">
                <i class="fas fa-history"></i> Recent SMS History
            </h5>
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Date</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Type</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Status</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Sent By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($smsLogs as $log): ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?= date('M j, Y g:i A', strtotime($log['sent_at'])) ?>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <span class="badge badge-info"><?= ucfirst($log['message_type']) ?></span>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?php if ($log['status'] === 'sent'): ?>
                                    <span style="color: #10b981;"><i class="fas fa-check"></i> Sent</span>
                                <?php else: ?>
                                    <span style="color: #dc2626;"><i class="fas fa-times"></i> Failed</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?= Utils::escape($log['sent_by_name'] ?? 'System') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-muted">
            <i class="fas fa-info-circle"></i> Add a phone number to send SMS invitations.
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- WhatsApp Share Section -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);">
        <h3 style="color: #166534;"><i class="fab fa-whatsapp"></i> WhatsApp Share</h3>
        <?php if ($lastWhatsappShare): ?>
        <span class="badge badge-success">
            <i class="fas fa-check"></i> Sent
        </span>
        <?php else: ?>
        <span class="badge" style="background: #fef3c7; color: #92400e;">
            <i class="fas fa-clock"></i> Not Sent
        </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- WhatsApp Status -->
        <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 24px;">
            <div style="flex: 1; min-width: 200px; background: #f9fafb; padding: 16px; border-radius: 8px;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">Phone Number</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                    <?php if (!empty($participant['phone'])): ?>
                        <i class="fab fa-whatsapp" style="color: #25d366;"></i>
                        <?= Utils::escape($participant['phone']) ?>
                    <?php else: ?>
                        <span style="color: #dc2626;"><i class="fas fa-times-circle"></i> No phone number</span>
                    <?php endif; ?>
                </p>
            </div>
            <div style="flex: 1; min-width: 200px; background: <?= $lastWhatsappShare ? '#ecfdf5' : '#fef3c7' ?>; padding: 16px; border-radius: 8px; border: 1px solid <?= $lastWhatsappShare ? '#a7f3d0' : '#fcd34d' ?>;">
                <p style="margin: 0; color: #6b7280; font-size: 13px;">WhatsApp Status</p>
                <p style="margin: 4px 0 0 0; font-size: 16px; font-weight: 600;">
                    <?php if ($lastWhatsappShare): ?>
                        <span style="color: #10b981;">
                            <i class="fas fa-check-circle"></i> Sent on <?= date('M j, Y g:i A', strtotime($lastWhatsappShare['shared_at'])) ?>
                        </span>
                    <?php else: ?>
                        <span style="color: #92400e;"><i class="fas fa-clock"></i> Not sent yet</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <!-- WhatsApp Actions -->
        <?php if (!empty($participant['phone'])): ?>
        <div id="whatsappActions">
            <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                <?php if (!$lastWhatsappShare): ?>
                <button type="button" id="shareWhatsAppBtn" class="btn" style="background: #25d366; color: white;" onclick="shareViaWhatsApp()">
                    <i class="fab fa-whatsapp"></i> Send via WhatsApp
                </button>
                <?php else: ?>
                <button type="button" id="shareWhatsAppBtn" class="btn btn-outline" style="color: #25d366; border-color: #25d366;" onclick="shareViaWhatsApp()">
                    <i class="fab fa-whatsapp"></i> Resend via WhatsApp
                </button>
                <?php endif; ?>
                <button type="button" id="confirmSentBtn" class="btn" style="background: #10b981; color: white; display: none;" onclick="confirmWhatsAppSent()">
                    <i class="fas fa-check"></i> Confirm Sent
                </button>
                <button type="button" id="cancelConfirmBtn" class="btn btn-outline" style="display: none;" onclick="cancelWhatsAppConfirm()">
                    Cancel
                </button>
                <span id="whatsappStatus" style="font-size: 14px;"></span>
            </div>
        </div>
        
        <!-- WhatsApp History -->
        <?php if (!empty($whatsappLogs)): ?>
        <div style="margin-top: 24px;">
            <h5 style="color: #374151; font-size: 14px; margin-bottom: 12px;">
                <i class="fas fa-history"></i> Recent Share History
            </h5>
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                    <thead>
                        <tr style="background: #f9fafb;">
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Date</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Type</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb;">Shared By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($whatsappLogs as $log): ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?= date('M j, Y g:i A', strtotime($log['shared_at'])) ?>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <span class="badge badge-success"><?= ucfirst($log['message_type']) ?></span>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb;">
                                <?= Utils::escape($log['shared_by_name'] ?? 'System') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-muted">
            <i class="fas fa-info-circle"></i> Add a phone number to share via WhatsApp.
        </p>
        <?php endif; ?>
    </div>
</div>

<!-- Danger Zone -->
<div class="card" style="border-color: #fecaca; margin-top: 24px;">
    <div class="card-header" style="background: #fef2f2;">
        <h3 style="color: #dc2626;"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
    </div>
    <div class="card-body">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <p style="margin: 0; font-weight: 600; color: #111827;">Reset Check-in Status</p>
                <p style="margin: 4px 0 0 0; color: #6b7280; font-size: 14px;">
                    Clear all check-in data for this participant. This cannot be undone.
                </p>
            </div>
            <button type="button" class="btn btn-outline" style="color: #dc2626; border-color: #dc2626;" onclick="showResetModal()">
                <i class="fas fa-undo"></i> Reset Check-in
            </button>
        </div>
    </div>
</div>

<!-- Reset Check-in Confirmation Modal -->
<div id="resetModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; overflow-y: auto;">
    <div style="min-height: 100%; display: flex; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
        <div style="background: white; border-radius: 16px; max-width: 420px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden;">
            <div style="padding: 24px 24px 0; text-align: center;">
                <div style="width: 64px; height: 64px; background: #fef2f2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 28px; color: #dc2626;"></i>
                </div>
                <h3 style="margin: 0 0 8px; font-size: 20px; color: #111827;">Reset Check-in Status</h3>
                <p style="margin: 0; color: #6b7280; font-size: 14px;">
                    Are you sure you want to reset the check-in status for <strong><?= Utils::escape($participant['name']) ?></strong>?
                </p>
            </div>
            <div style="padding: 20px 24px;">
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; font-size: 14px; color: #991b1b;">
                    <i class="fas fa-info-circle"></i> This will:
                    <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                        <li>Clear all guest check-in counts</li>
                        <li>Reset the participant to "Not checked in" status</li>
                        <li>Allow them to check in again</li>
                    </ul>
                </div>
                <div id="resetStatus" style="margin-top: 12px; text-align: center;"></div>
            </div>
            <div style="padding: 16px 24px 24px; display: flex; gap: 12px; justify-content: center;">
                <button type="button" class="btn btn-outline" id="resetCancelBtn" onclick="closeResetModal()">
                    Cancel
                </button>
                <button type="button" class="btn" style="background: #dc2626; color: white;" id="resetConfirmBtn" onclick="executeReset()">
                    <i class="fas fa-undo"></i> Reset Check-in
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const basePath = '<?= $basePath ?>';
    const participantId = <?= $participantId ?>;
    
    // Make functions globally available
    window.showResetModal = function() {
        const modal = document.getElementById('resetModal');
        if (modal) {
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            const statusEl = document.getElementById('resetStatus');
            if (statusEl) statusEl.innerHTML = '';
            // Reset button states
            const confirmBtn = document.getElementById('resetConfirmBtn');
            const cancelBtn = document.getElementById('resetCancelBtn');
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-undo"></i> Reset Check-in';
                confirmBtn.style.display = '';
            }
            if (cancelBtn) {
                cancelBtn.style.display = '';
            }
        }
    };

    window.closeResetModal = function() {
        const modal = document.getElementById('resetModal');
        if (modal) {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    window.executeReset = async function() {
        const confirmBtn = document.getElementById('resetConfirmBtn');
        const cancelBtn = document.getElementById('resetCancelBtn');
        const status = document.getElementById('resetStatus');
        
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
        }
        if (cancelBtn) {
            cancelBtn.style.display = 'none';
        }
        if (status) {
            status.innerHTML = '';
        }
        
        try {
            const response = await fetch(basePath + '/api/reset-checkin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ participant_id: participantId })
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (status) {
                    status.innerHTML = '<div style="color: #10b981; padding: 12px; background: #ecfdf5; border-radius: 8px;"><i class="fas fa-check-circle"></i> Check-in status has been reset successfully!</div>';
                }
                if (confirmBtn) {
                    confirmBtn.style.display = 'none';
                }
                
                // Reload page after short delay
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                if (status) {
                    status.innerHTML = '<div style="color: #dc2626; padding: 12px; background: #fef2f2; border-radius: 8px;"><i class="fas fa-exclamation-circle"></i> ' + (result.message || 'An error occurred') + '</div>';
                }
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fas fa-undo"></i> Reset Check-in';
                }
                if (cancelBtn) {
                    cancelBtn.style.display = '';
                }
            }
        } catch (error) {
            console.error('Reset error:', error);
            if (status) {
                status.innerHTML = '<div style="color: #dc2626; padding: 12px; background: #fef2f2; border-radius: 8px;"><i class="fas fa-exclamation-circle"></i> Failed to reset check-in status. Please try again.</div>';
            }
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '<i class="fas fa-undo"></i> Reset Check-in';
            }
            if (cancelBtn) {
                cancelBtn.style.display = '';
            }
        }
    };

    // Close on click outside modal content
    document.getElementById('resetModal').onclick = function(e) {
        if (e.target === this) {
            closeResetModal();
        }
    };
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('resetModal');
            if (modal && modal.style.display !== 'none') {
                closeResetModal();
            }
        }
    });
})();
</script>

<script>
const basePath = '<?= $basePath ?>';
const participantId = <?= $participantId ?>;

async function sendSms(forceResend = false) {
    const status = document.getElementById('smsStatus');
    const btn = event.target.closest('button');
    
    if (!forceResend) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    } else {
        if (!confirm('This participant already received an SMS. Send again?')) {
            return;
        }
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    }
    
    status.innerHTML = '';
    
    try {
        const response = await fetch(basePath + '/api/send-sms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                participant_id: <?= $participantId ?>,
                force_resend: forceResend
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            status.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check-circle"></i> ' + result.message + '</span>';
            // Reload after success to update UI
            setTimeout(() => location.reload(), 1500);
        } else {
            status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> ' + result.message + '</span>';
            btn.disabled = false;
            btn.innerHTML = forceResend 
                ? '<i class="fas fa-redo"></i> Resend Invitation SMS'
                : '<i class="fas fa-paper-plane"></i> Send Invitation SMS';
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> Failed to send SMS</span>';
        btn.disabled = false;
        btn.innerHTML = forceResend 
            ? '<i class="fas fa-redo"></i> Resend Invitation SMS'
            : '<i class="fas fa-paper-plane"></i> Send Invitation SMS';
    }
}

async function sendEmail(forceResend = false) {
    const status = document.getElementById('emailStatus');
    const btn = event.target.closest('button');
    
    if (forceResend) {
        if (!confirm('This participant already received an email. Send again?')) {
            return;
        }
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    status.innerHTML = '';
    
    try {
        const response = await fetch(basePath + '/api/send-email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                participant_id: <?= $participantId ?>,
                force_resend: forceResend
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            status.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check-circle"></i> ' + result.message + '</span>';
            // Reload after success to update UI
            setTimeout(() => location.reload(), 1500);
        } else {
            status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> ' + result.message + '</span>';
            btn.disabled = false;
            btn.innerHTML = forceResend 
                ? '<i class="fas fa-redo"></i> Resend Invitation Email'
                : '<i class="fas fa-paper-plane"></i> Send Invitation Email';
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> Failed to send email</span>';
        btn.disabled = false;
        btn.innerHTML = forceResend 
            ? '<i class="fas fa-redo"></i> Resend Invitation Email'
            : '<i class="fas fa-paper-plane"></i> Send Invitation Email';
    }
}

async function shareViaWhatsApp() {
    const status = document.getElementById('whatsappStatus');
    const btn = event.target.closest('button');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    status.innerHTML = '';
    
    try {
        const response = await fetch(basePath + '/api/whatsapp-share.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                participant_id: <?= $participantId ?>,
                log_share: false  // Don't log yet - wait for confirmation
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Open WhatsApp in a new window/tab
            window.open(result.url, '_blank');
            
            // Show confirm/cancel buttons
            btn.style.display = 'none';
            document.getElementById('confirmSentBtn').style.display = '';
            document.getElementById('cancelConfirmBtn').style.display = '';
            status.innerHTML = '<span style="color: #f59e0b;"><i class="fas fa-info-circle"></i> Did you send the message? Click "Confirm Sent" to log it.</span>';
        } else {
            status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> ' + result.message + '</span>';
            btn.disabled = false;
            btn.innerHTML = '<i class="fab fa-whatsapp"></i> Share via WhatsApp';
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> Failed to generate WhatsApp link</span>';
        btn.disabled = false;
        btn.innerHTML = '<i class="fab fa-whatsapp"></i> Share via WhatsApp';
    }
}

async function confirmWhatsAppSent() {
    const status = document.getElementById('whatsappStatus');
    const confirmBtn = document.getElementById('confirmSentBtn');
    const cancelBtn = document.getElementById('cancelConfirmBtn');
    
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging...';
    
    try {
        const response = await fetch(basePath + '/api/whatsapp-share.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                participant_id: <?= $participantId ?>,
                log_share: true  // Now log it
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            status.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check-circle"></i> WhatsApp share confirmed and logged!</span>';
            // Reload to show updated history
            setTimeout(() => location.reload(), 1500);
        } else {
            status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> ' + result.message + '</span>';
            resetWhatsAppButtons();
        }
    } catch (error) {
        console.error('Error:', error);
        status.innerHTML = '<span style="color: #dc2626;"><i class="fas fa-exclamation-circle"></i> Failed to log share</span>';
        resetWhatsAppButtons();
    }
}

function cancelWhatsAppConfirm() {
    const status = document.getElementById('whatsappStatus');
    status.innerHTML = '<span style="color: #6b7280;"><i class="fas fa-info-circle"></i> Share cancelled - not logged.</span>';
    resetWhatsAppButtons();
}

function resetWhatsAppButtons() {
    const shareBtn = document.getElementById('shareWhatsAppBtn');
    const confirmBtn = document.getElementById('confirmSentBtn');
    const cancelBtn = document.getElementById('cancelConfirmBtn');
    
    shareBtn.style.display = '';
    shareBtn.disabled = false;
    shareBtn.innerHTML = '<i class="fab fa-whatsapp"></i> Share via WhatsApp';
    confirmBtn.style.display = 'none';
    confirmBtn.disabled = false;
    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm Sent';
    cancelBtn.style.display = 'none';
}

function copyCardLink() {
    const linkElement = document.getElementById('cardDownloadLink');
    const status = document.getElementById('copyStatus');
    
    if (!linkElement) return;
    
    const link = linkElement.textContent;
    
    navigator.clipboard.writeText(link).then(() => {
        status.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check-circle"></i> Link copied to clipboard!</span>';
        setTimeout(() => {
            status.innerHTML = '';
        }, 3000);
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = link;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        status.innerHTML = '<span style="color: #10b981;"><i class="fas fa-check-circle"></i> Link copied to clipboard!</span>';
        setTimeout(() => {
            status.innerHTML = '';
        }, 3000);
    });
}

// ===== Check-in Functionality =====
const checkinData = {
    participantId: <?= $participantId ?>,
    uniqueId: '<?= Utils::escape($participant['unique_id']) ?>',
    name: '<?= Utils::escape(addslashes($participant['name'])) ?>',
    totalGuests: <?= $participant['total_guests'] ?>,
    guestsCheckedIn: <?= $participant['guests_checked_in'] ?>
};

function openCheckinModal() {
    const guestsRemaining = checkinData.totalGuests - checkinData.guestsCheckedIn;
    
    document.getElementById('checkinGuestsCount').value = guestsRemaining;
    document.getElementById('checkinGuestsCount').max = guestsRemaining;
    
    document.getElementById('checkinModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCheckinModal() {
    document.getElementById('checkinModal').classList.remove('active');
    document.body.style.overflow = '';
}

function adjustGuestCount(delta) {
    const input = document.getElementById('checkinGuestsCount');
    const guestsRemaining = checkinData.totalGuests - checkinData.guestsCheckedIn;
    let newValue = parseInt(input.value) + delta;
    if (newValue < 1) newValue = 1;
    if (newValue > guestsRemaining) newValue = guestsRemaining;
    input.value = newValue;
}

async function executeCheckin() {
    const guestsCount = parseInt(document.getElementById('checkinGuestsCount').value);
    const guestsRemaining = checkinData.totalGuests - checkinData.guestsCheckedIn;
    
    if (guestsCount < 1 || guestsCount > guestsRemaining) {
        alert('Invalid number of guests');
        return;
    }
    
    const btn = document.getElementById('checkinBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking in...';
    
    try {
        const response = await fetch('<?= $basePath ?>/api/checkin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                unique_id: checkinData.uniqueId,
                guests_count: guestsCount,
                gate_location: 'Admin Panel',
                notes: 'Manual check-in from edit page'
            })
        });
        
        const result = await response.json();
        
        if (result.success || result.status === 'checked_in' || result.status === 'already_checked') {
            closeCheckinModal();
            // Reload the page to show updated status
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Check-in failed'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Check In';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to check in participant. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Check In';
    }
}

// Close check-in modal on overlay click
document.getElementById('checkinModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeCheckinModal();
    }
});

// ===== Reset Check-in Functionality =====
function openResetCheckinModal() {
    document.getElementById('resetCheckinModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeResetCheckinModal() {
    document.getElementById('resetCheckinModal').classList.remove('active');
    document.body.style.overflow = '';
}

async function executeResetCheckin() {
    const btn = document.getElementById('resetCheckinBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    
    try {
        const response = await fetch('<?= $basePath ?>/api/reset-checkin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ participant_id: checkinData.participantId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeResetCheckinModal();
            // Reload the page to show updated status
            location.reload();
        } else {
            alert('Error: ' + result.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-undo"></i> Reset';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to reset check-in. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-undo"></i> Reset';
    }
}

// Close reset modal on overlay click
document.getElementById('resetCheckinModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeResetCheckinModal();
    }
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCheckinModal();
        closeResetCheckinModal();
    }
});
</script>

<!-- Check-in Modal -->
<div class="modal-overlay" id="checkinModal">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-icon success">
                <i class="fas fa-user-check"></i>
            </div>
            <h3>Check In Guest(s)</h3>
        </div>
        <div class="modal-body">
            <p>Checking in guests for <strong><?= Utils::escape($participant['name']) ?></strong></p>
            
            <div class="guest-info-box">
                <div class="guest-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $participant['guests_checked_in'] ?></div>
                        <div class="stat-label">Checked In</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $participant['total_guests'] - $participant['guests_checked_in'] ?></div>
                        <div class="stat-label">Remaining</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= $participant['total_guests'] ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Number of guests to check in:</label>
                <div class="guest-counter">
                    <button type="button" class="btn btn-outline" onclick="adjustGuestCount(-1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" id="checkinGuestsCount" class="form-control" min="1" value="1" readonly>
                    <button type="button" class="btn btn-outline" onclick="adjustGuestCount(1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeCheckinModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn btn-success" onclick="executeCheckin()" id="checkinBtn">
                <i class="fas fa-check"></i> Check In
            </button>
        </div>
    </div>
</div>

<!-- Reset Check-in Modal -->
<div class="modal-overlay" id="resetCheckinModal">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-icon warning">
                <i class="fas fa-undo-alt"></i>
            </div>
            <h3>Reset Check-in</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to reset the check-in status for <strong><?= Utils::escape($participant['name']) ?></strong>?</p>
            <div class="guest-info-box" style="background: #fef3c7; margin-top: 16px;">
                <p style="margin: 0; font-size: 13px; color: #92400e;">
                    <i class="fas fa-exclamation-triangle"></i><br>
                    This will reset all guest check-ins for this participant back to zero.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeResetCheckinModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="btn" style="background: #f59e0b; color: white;" onclick="executeResetCheckin()" id="resetCheckinBtn">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
