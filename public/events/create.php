<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::requirePermission('events_manage');

$db = Connection::getInstance();
$pageTitle = 'Create Event';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $eventName = trim($_POST['event_name'] ?? '');
        $eventCode = trim($_POST['event_code'] ?? '');
        $eventDate = $_POST['event_date'] ?? null;
        $eventTime = $_POST['event_time'] ?? null;
        $eventVenue = trim($_POST['event_venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        
        if (empty($eventName)) {
            throw new Exception('Event name is required');
        }
        
        // Generate event code if not provided
        if (empty($eventCode)) {
            $eventCode = strtoupper(Utils::slugify($eventName) . '-' . date('Y'));
        }
        
        // Check if event code exists
        $existing = $db->queryOne("SELECT id FROM events WHERE event_code = :code", ['code' => $eventCode]);
        if ($existing) {
            throw new Exception('Event code already exists. Please use a different code.');
        }
        
        $db->insert("
            INSERT INTO events (event_name, event_code, event_date, event_time, event_venue, description, status, created_by)
            VALUES (:event_name, :event_code, :event_date, :event_time, :event_venue, :description, :status, :created_by)
        ", [
            'event_name' => $eventName,
            'event_code' => $eventCode,
            'event_date' => $eventDate ?: null,
            'event_time' => $eventTime ?: null,
            'event_venue' => $eventVenue,
            'description' => $description,
            'status' => $status,
            'created_by' => Auth::id()
        ]);
        
        Session::flash('success', 'Event created successfully!');
        Utils::redirect('/tukioqrcode/public/events/index.php');
        
    } catch (Exception $e) {
        Session::flash('error', $e->getMessage());
    }
}

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>Create New Event</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <?= Utils::csrfField() ?>
            
            <div class="form-group">
                <label for="event_name">Event Name *</label>
                <input 
                    type="text" 
                    id="event_name" 
                    name="event_name" 
                    class="form-control" 
                    required
                    placeholder="e.g., Annual Gala 2025"
                    value="<?= Utils::escape($_POST['event_name'] ?? '') ?>"
                >
            </div>
            
            <div class="form-group">
                <label for="event_code">Event Code</label>
                <input 
                    type="text" 
                    id="event_code" 
                    name="event_code" 
                    class="form-control"
                    placeholder="Leave blank to auto-generate"
                    value="<?= Utils::escape($_POST['event_code'] ?? '') ?>"
                >
                <small class="text-muted">Unique code for this event. Auto-generated if left blank.</small>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="event_date">Event Date</label>
                    <input 
                        type="date" 
                        id="event_date" 
                        name="event_date" 
                        class="form-control"
                        value="<?= Utils::escape($_POST['event_date'] ?? '') ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="event_time">Event Time</label>
                    <input 
                        type="time" 
                        id="event_time" 
                        name="event_time" 
                        class="form-control"
                        value="<?= Utils::escape($_POST['event_time'] ?? '') ?>"
                    >
                </div>
            </div>
            
            <div class="form-group">
                <label for="event_venue">Venue</label>
                <input 
                    type="text" 
                    id="event_venue" 
                    name="event_venue" 
                    class="form-control"
                    placeholder="e.g., Mlimani City Conference Centre"
                    value="<?= Utils::escape($_POST['event_venue'] ?? '') ?>"
                >
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea 
                    id="description" 
                    name="description" 
                    class="form-control" 
                    rows="3"
                    placeholder="Optional event description"
                ><?= Utils::escape($_POST['description'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                </select>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Event
                </button>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
