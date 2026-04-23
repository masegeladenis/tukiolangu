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

$pageTitle = 'Edit Event - ' . $event['event_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $eventName = trim($_POST['event_name'] ?? '');
        $eventDate = $_POST['event_date'] ?? null;
        $eventTime = $_POST['event_time'] ?? null;
        $eventVenue = trim($_POST['event_venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'draft';
        
        if (empty($eventName)) {
            throw new Exception('Event name is required');
        }
        
        $db->execute("
            UPDATE events 
            SET event_name = :event_name, 
                event_date = :event_date, 
                event_time = :event_time, 
                event_venue = :event_venue, 
                description = :description, 
                status = :status
            WHERE id = :id
        ", [
            'event_name' => $eventName,
            'event_date' => $eventDate ?: null,
            'event_time' => $eventTime ?: null,
            'event_venue' => $eventVenue,
            'description' => $description,
            'status' => $status,
            'id' => $eventId
        ]);
        
        Session::flash('success', 'Event updated successfully!');
        Utils::redirect('/tukioqrcode/public/events/view.php?id=' . $eventId);
        
    } catch (Exception $e) {
        Session::flash('error', $e->getMessage());
    }
}

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>Edit Event</h3>
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
                    value="<?= Utils::escape($event['event_name']) ?>"
                >
            </div>
            
            <div class="form-group">
                <label>Event Code</label>
                <input 
                    type="text" 
                    class="form-control"
                    value="<?= Utils::escape($event['event_code']) ?>"
                    disabled
                >
                <small class="text-muted">Event code cannot be changed.</small>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="event_date">Event Date</label>
                    <input 
                        type="date" 
                        id="event_date" 
                        name="event_date" 
                        class="form-control"
                        value="<?= $event['event_date'] ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="event_time">Event Time</label>
                    <input 
                        type="time" 
                        id="event_time" 
                        name="event_time" 
                        class="form-control"
                        value="<?= $event['event_time'] ?>"
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
                    value="<?= Utils::escape($event['event_venue']) ?>"
                >
            </div>
            
            <div class="form-group">
                <label for="description">Description</label>
                <textarea 
                    id="description" 
                    name="description" 
                    class="form-control" 
                    rows="3"
                ><?= Utils::escape($event['description']) ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="draft" <?= $event['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="active" <?= $event['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $event['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $event['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
                <a href="view.php?id=<?= $eventId ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
