<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::requirePermission('reports');

$db = Connection::getInstance();
$pageTitle = 'Reports & Analytics';

// Scope all data to assigned events
$assignedIds  = Auth::getAssignedEventIds();
$pdo          = $db->getConnection();
$events       = [];
$todayCheckins = [];
$ticketStats   = [];

if (!empty($assignedIds)) {
    $in = implode(',', array_fill(0, count($assignedIds), '?'));

    $s = $pdo->prepare("
        SELECT e.*,
            COUNT(DISTINCT p.id) as participant_count,
            COALESCE(SUM(p.total_guests), 0) as total_guests,
            COALESCE(SUM(p.guests_checked_in), 0) as guests_checked_in
        FROM events e
        LEFT JOIN participants p ON e.id = p.event_id AND p.status = 'active'
        WHERE e.id IN ($in)
        GROUP BY e.id
        ORDER BY e.event_date DESC
    ");
    $s->execute($assignedIds);
    $events = $s->fetchAll();

    $s2 = $pdo->prepare("
        SELECT cl.created_at, cl.guests_this_checkin,
               p.name, p.unique_id, e.event_name
        FROM checkin_logs cl
        JOIN participants p ON cl.participant_id = p.id
        JOIN events e ON cl.event_id = e.id
        WHERE DATE(cl.created_at) = CURDATE() AND cl.action = 'check_in'
        AND cl.event_id IN ($in)
        ORDER BY cl.created_at DESC
        LIMIT 50
    ");
    $s2->execute($assignedIds);
    $todayCheckins = $s2->fetchAll();

    $s3 = $pdo->prepare("
        SELECT ticket_type,
               COUNT(*) as count,
               SUM(total_guests) as total_guests,
               SUM(guests_checked_in) as checked_in
        FROM participants
        WHERE status = 'active' AND event_id IN ($in)
        GROUP BY ticket_type
        ORDER BY count DESC
    ");
    $s3->execute($assignedIds);
    $ticketStats = $s3->fetchAll();
}

ob_start();
?>

<div class="stats-row">
    <?php
    $totalEvents = count($events);
    $totalParticipants = array_sum(array_column($events, 'participant_count'));
    $totalGuests = array_sum(array_column($events, 'total_guests'));
    $totalCheckedIn = array_sum(array_column($events, 'guests_checked_in'));
    $overallRate = $totalGuests > 0 ? round(($totalCheckedIn / $totalGuests) * 100, 1) : 0;
    ?>
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $totalEvents ?></h3>
            <p>Total Events</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $totalParticipants ?></h3>
            <p>Total Participants</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $totalCheckedIn ?></h3>
            <p>Total Checked In</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-content">
            <h3><?= $overallRate ?>%</h3>
            <p>Overall Check-in Rate</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3>Event Performance</h3>
    </div>
    <div class="card-body">
        <?php if (empty($events)): ?>
            <p class="text-muted">No events yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>Participants</th>
                            <th>Total Guests</th>
                            <th>Checked In</th>
                            <th>Rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): 
                            $rate = $event['total_guests'] > 0 
                                ? round(($event['guests_checked_in'] / $event['total_guests']) * 100, 1) 
                                : 0;
                        ?>
                        <tr>
                            <td><strong><?= Utils::escape($event['event_name']) ?></strong></td>
                            <td><?= Utils::formatDate($event['event_date']) ?></td>
                            <td><?= $event['participant_count'] ?></td>
                            <td><?= $event['total_guests'] ?></td>
                            <td><?= $event['guests_checked_in'] ?></td>
                            <td>
                                <div class="progress-small">
                                    <div class="progress-bar" style="width: <?= $rate ?>%"></div>
                                </div>
                                <small><?= $rate ?>%</small>
                            </td>
                            <td><span class="badge badge-<?= $event['status'] ?>"><?= ucfirst($event['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="form-row">
    <div class="card">
        <div class="card-header">
            <h3>Ticket Type Breakdown</h3>
        </div>
        <div class="card-body">
            <?php if (empty($ticketStats)): ?>
                <p class="text-muted">No data yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Ticket Type</th>
                                <th>Count</th>
                                <th>Guests</th>
                                <th>Checked</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ticketStats as $stat): ?>
                            <tr>
                                <td><span class="badge badge-info"><?= Utils::escape($stat['ticket_type']) ?></span></td>
                                <td><?= $stat['count'] ?></td>
                                <td><?= $stat['total_guests'] ?></td>
                                <td><?= $stat['checked_in'] ?></td>
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
            <h3>Today's Check-ins</h3>
        </div>
        <div class="card-body">
            <?php if (empty($todayCheckins)): ?>
                <p class="text-muted">No check-ins today.</p>
            <?php else: ?>
                <div style="max-height: 300px; overflow-y: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Name</th>
                                <th>Guests</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($todayCheckins as $checkin): ?>
                            <tr>
                                <td><small><?= date('H:i', strtotime($checkin['created_at'])) ?></small></td>
                                <td><small><?= Utils::escape($checkin['name']) ?></small></td>
                                <td><small><?= $checkin['guests_this_checkin'] ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
