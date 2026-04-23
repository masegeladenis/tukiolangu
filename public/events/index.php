<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::requirePermission('events_view');

$db = Connection::getInstance();
$pageTitle = 'Events';

// Scope to assigned events
$assignedIds = Auth::getAssignedEventIds();
$events = [];
if (!empty($assignedIds)) {
    $in = implode(',', array_fill(0, count($assignedIds), '?'));
    $stmt = $db->getConnection()->prepare("
        SELECT
            e.*,
            COUNT(DISTINCT p.id) as participant_count,
            COALESCE(SUM(p.total_guests), 0) as total_guests,
            COALESCE(SUM(p.guests_checked_in), 0) as guests_checked_in
        FROM events e
        LEFT JOIN participants p ON e.id = p.event_id AND p.status = 'active'
        WHERE e.id IN ($in)
        GROUP BY e.id
        ORDER BY e.created_at DESC
    ");
    $stmt->execute($assignedIds);
    $events = $stmt->fetchAll();
}

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>All Events</h3>
        <a href="create.php" class="btn btn-primary">
            Create Event
        </a>
    </div>
    <div class="card-body">
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <p>No events yet. Create your first event to get started.</p>
                <a href="create.php" class="btn btn-primary">
                    Create Event
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Event Code</th>
                            <th>Date</th>
                            <th>Venue</th>
                            <th>Participants</th>
                            <th>Check-in Rate</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): 
                            $totalGuests = (int) $event['total_guests'];
                            $checkedIn = (int) $event['guests_checked_in'];
                            $rate = $totalGuests > 0 ? round(($checkedIn / $totalGuests) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><strong><?= Utils::escape($event['event_name']) ?></strong></td>
                            <td><code><?= Utils::escape($event['event_code']) ?></code></td>
                            <td><?= Utils::formatDate($event['event_date']) ?></td>
                            <td><?= Utils::escape($event['event_venue']) ?: 'TBD' ?></td>
                            <td><?= $event['participant_count'] ?> (<?= $totalGuests ?> guests)</td>
                            <td>
                                <div class="progress-small">
                                    <div class="progress-bar" style="width: <?= $rate ?>%"></div>
                                </div>
                                <small><?= $checkedIn ?> / <?= $totalGuests ?> (<?= $rate ?>%)</small>
                            </td>
                            <td>
                                <span class="badge badge-<?= $event['status'] ?>">
                                    <?= ucfirst($event['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="view.php?id=<?= $event['id'] ?>" class="btn btn-sm btn-outline">
                                    View
                                </a>
                                <a href="edit.php?id=<?= $event['id'] ?>" class="btn btn-sm btn-outline">
                                    Edit
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
