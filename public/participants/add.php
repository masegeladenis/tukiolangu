<?php
/**
 * Add Participant Page
 * Allows adding individual participants to an event
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;
use App\Services\QRCodeGenerator;

Session::start();
Auth::requireAdmin();

$db = Connection::getInstance();

// Get events for dropdown
$events = $db->query("SELECT id, event_name, event_code FROM events WHERE status != 'cancelled' ORDER BY created_at DESC");

$preselectedEventId = (int) ($_GET['event_id'] ?? 0);
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = Utils::formatPhoneNumber($_POST['phone'] ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $ticketType = trim($_POST['ticket_type'] ?? 'Standard');
    $totalGuests = (int) ($_POST['total_guests'] ?? 1);
    $notes = trim($_POST['notes'] ?? '');
    
    // Validation
    if ($eventId <= 0) {
        $error = 'Please select an event';
    } elseif (empty($name)) {
        $error = 'Name is required';
    } elseif ($totalGuests < 1) {
        $error = 'Total guests must be at least 1';
    } else {
        // Get event info
        $event = $db->queryOne("SELECT * FROM events WHERE id = :id", ['id' => $eventId]);
        
        if (!$event) {
            $error = 'Selected event not found';
        } else {
            // Check if we need to create a batch for manual entries
            $batch = $db->queryOne("
                SELECT * FROM batches 
                WHERE event_id = :event_id AND batch_name = 'Manual Entries'
            ", ['event_id' => $eventId]);
            
            if (!$batch) {
                // Create a batch for manual entries
                $batchId = $db->insert("
                    INSERT INTO batches (event_id, batch_name, design_path, excel_path, status, total_cards, processed)
                    VALUES (:event_id, 'Manual Entries', '', '', 'completed', 0, 0)
                ", ['event_id' => $eventId]);
            } else {
                $batchId = $batch['id'];
            }
            
            // Generate unique ID using event code
            $uniqueId = Utils::generateUniqueId($event['event_code']);
            
            // Make sure unique ID doesn't exist
            while ($db->queryOne("SELECT id FROM participants WHERE unique_id = :uid", ['uid' => $uniqueId])) {
                $uniqueId = Utils::generateUniqueId($event['event_code']);
            }
            
            // Build QR data array
            $qrDataArray = [
                'id' => $uniqueId,
                'name' => $name,
                'event' => $event['event_code'],
                'ticket' => $ticketType,
                'guests' => $totalGuests
            ];
            
            $qrDataJson = json_encode($qrDataArray);
            
            try {
                // Generate QR code
                $qrGenerator = new QRCodeGenerator();
                $qrPath = $qrGenerator->generate($qrDataArray, $uniqueId);
                
                // Insert participant
                $participantId = $db->insert("
                    INSERT INTO participants (
                        batch_id, event_id, name, email, phone, organization,
                        unique_id, ticket_type, total_guests, guests_remaining,
                        qr_data, qr_code_path, notes, status
                    ) VALUES (
                        :batch_id, :event_id, :name, :email, :phone, :organization,
                        :unique_id, :ticket_type, :total_guests, :guests_remaining,
                        :qr_data, :qr_code_path, :notes, 'active'
                    )
                ", [
                    'batch_id' => $batchId,
                    'event_id' => $eventId,
                    'name' => $name,
                    'email' => $email ?: null,
                    'phone' => $phone ?: null,
                    'organization' => $organization ?: null,
                    'unique_id' => $uniqueId,
                    'ticket_type' => $ticketType,
                    'total_guests' => $totalGuests,
                    'guests_remaining' => $totalGuests,
                    'qr_data' => $qrDataJson,
                    'qr_code_path' => $qrPath,
                    'notes' => $notes ?: null
                ]);
                
                // Update batch count
                $db->execute("
                    UPDATE batches SET total_cards = total_cards + 1, processed = processed + 1
                    WHERE id = :batch_id
                ", ['batch_id' => $batchId]);
                
                $success = "Participant added successfully! Unique ID: {$uniqueId}";
                
                // Clear form
                $preselectedEventId = $eventId;
                $name = $email = $phone = $organization = $notes = '';
                $ticketType = 'Standard';
                $totalGuests = 1;
                
            } catch (Exception $e) {
                $error = 'Error creating participant: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Add Participant';
$basePath = Utils::basePath();

ob_start();
?>

<div class="page-header">
    <div>
        <h2>Add Participant</h2>
        <p class="text-muted">Manually add a participant to an event</p>
    </div>
    <a href="index.php<?= $preselectedEventId > 0 ? '?event_id=' . $preselectedEventId : '' ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Back to Participants
    </a>
</div>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-user-plus"></i> New Participant</h3>
    </div>
    <div class="card-body">
        <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= Utils::escape($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $success ?>
        </div>
        <?php endif; ?>
        
        <?php if (empty($events)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> No events available. Please create an event first.
            <a href="<?= $basePath ?>/events/create.php" class="btn btn-sm btn-primary" style="margin-left: 12px;">
                Create Event
            </a>
        </div>
        <?php else: ?>
        
        <form method="POST" action="">
            <?= Utils::csrfField() ?>
            
            <!-- Event Selection -->
            <div class="form-group">
                <label for="event_id">Select Event <span style="color: #dc2626;">*</span></label>
                <select id="event_id" name="event_id" class="form-control" required>
                    <option value="">-- Choose an Event --</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?= $event['id'] ?>" <?= $preselectedEventId == $event['id'] ? 'selected' : '' ?>>
                        <?= Utils::escape($event['event_name']) ?> (<?= Utils::escape($event['event_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <hr style="margin: 24px 0; border-color: #e5e7eb;">
            
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
                            value="<?= Utils::escape($_POST['name'] ?? '') ?>"
                            placeholder="Enter full name"
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
                            value="<?= Utils::escape($_POST['email'] ?? '') ?>"
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
                            value="<?= Utils::escape($_POST['phone'] ?? '') ?>"
                            placeholder="+255 xxx xxx xxx"
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="organization">Organization</label>
                        <input 
                            type="text" 
                            id="organization" 
                            name="organization" 
                            class="form-control"
                            value="<?= Utils::escape($_POST['organization'] ?? '') ?>"
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
                            value="<?= Utils::escape($_POST['ticket_type'] ?? 'Standard') ?>"
                            placeholder="e.g., VIP, Regular, Student"
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
                            value="<?= (int) ($_POST['total_guests'] ?? 1) ?>"
                            min="1"
                            required
                        >
                        <small class="text-muted">
                            Number of people this ticket admits (including the participant)
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea 
                            id="notes" 
                            name="notes" 
                            class="form-control"
                            rows="4"
                            placeholder="Any additional notes about this participant..."
                        ><?= Utils::escape($_POST['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                <a href="index.php<?= $preselectedEventId > 0 ? '?event_id=' . $preselectedEventId : '' ?>" class="btn btn-outline">
                    Cancel
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Participant
                </button>
            </div>
        </form>
        
        <?php endif; ?>
    </div>
</div>

<!-- Info Card -->
<div class="card" style="margin-top: 24px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-color: #bfdbfe;">
    <div class="card-body">
        <h4 style="color: #1e40af; margin-bottom: 12px;">
            <i class="fas fa-info-circle"></i> About Manual Participant Entry
        </h4>
        <ul style="color: #1e3a8a; margin: 0; padding-left: 20px; line-height: 1.8;">
            <li>A unique ID and QR code will be automatically generated for each participant</li>
            <li>You can send invitations (email/SMS) after adding the participant</li>
            <li>Manual entries are grouped under a "Manual Entries" batch for organization</li>
            <li>For bulk imports, use the <a href="<?= $basePath ?>/batches/upload.php" style="color: #2563eb;">batch upload</a> feature with Excel files</li>
        </ul>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
