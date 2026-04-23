<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::require();

$db = Connection::getInstance();
$user = Auth::user();

// Get statistics
$stats = [
    'total_events' => 0,
    'active_events' => 0,
    'total_participants' => 0,
    'checked_in_today' => 0
];

try {
    // Scope all queries to events the current user can access
    $assignedIds = Auth::getAssignedEventIds();
    $hasEvents = !empty($assignedIds);
    $idPlaceholders = $hasEvents ? implode(',', array_fill(0, count($assignedIds), '?')) : '0';

    $eventStats = $hasEvents ? $db->getConnection()->prepare("
        SELECT 
            COUNT(*) as total_events,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_events
        FROM events
        WHERE id IN ($idPlaceholders)
    ") : null;

    if ($eventStats) {
        $eventStats->execute($assignedIds);
        $eventStatsRow = $eventStats->fetch();
        $stats['total_events'] = (int) ($eventStatsRow['total_events'] ?? 0);
        $stats['active_events'] = (int) ($eventStatsRow['active_events'] ?? 0);
    }

    $pStmt = $hasEvents ? $db->getConnection()->prepare("
        SELECT 
            COUNT(*) as total_participants,
            SUM(guests_checked_in) as total_checked_in
        FROM participants
        WHERE status = 'active' AND event_id IN ($idPlaceholders)
    ") : null;

    if ($pStmt) {
        $pStmt->execute($assignedIds);
        $participantStatsRow = $pStmt->fetch();
        $stats['total_participants'] = (int) ($participantStatsRow['total_participants'] ?? 0);
        $stats['total_checked_in'] = (int) ($participantStatsRow['total_checked_in'] ?? 0);
    }

    // Count check-ins today scoped to assigned events
    $ciStmt = $hasEvents ? $db->getConnection()->prepare("
        SELECT COUNT(DISTINCT cl.participant_id) as count
        FROM checkin_logs cl
        JOIN participants p ON cl.participant_id = p.id
        WHERE DATE(cl.created_at) = CURDATE()
        AND cl.action = 'check_in'
        AND p.guests_checked_in > 0
        AND cl.event_id IN ($idPlaceholders)
    ") : null;

    if ($ciStmt) {
        $ciStmt->execute($assignedIds);
        $checkedInTodayRow = $ciStmt->fetch();
        $stats['checked_in_today'] = (int) ($checkedInTodayRow['count'] ?? 0);
    }

    // Get recent events scoped to assigned events
    $reStmt = $hasEvents ? $db->getConnection()->prepare("
        SELECT 
            e.*,
            COUNT(p.id) as participant_count,
            SUM(p.total_guests) as total_guests,
            SUM(p.guests_checked_in) as guests_checked_in
        FROM events e
        LEFT JOIN participants p ON e.id = p.event_id AND p.status = 'active'
        WHERE e.id IN ($idPlaceholders)
        GROUP BY e.id
        ORDER BY e.created_at DESC
        LIMIT 5
    ") : null;

    $recentEvents = [];
    if ($reStmt) {
        $reStmt->execute($assignedIds);
        $recentEvents = $reStmt->fetchAll();
    }

    // Get recent check-ins scoped to assigned events
    $rcStmt = $hasEvents ? $db->getConnection()->prepare("
        SELECT 
            cl.*,
            p.name,
            p.unique_id,
            p.ticket_type,
            p.guests_checked_in,
            e.event_name
        FROM checkin_logs cl
        JOIN participants p ON cl.participant_id = p.id
        JOIN events e ON cl.event_id = e.id
        WHERE cl.action = 'check_in'
        AND p.guests_checked_in > 0
        AND cl.event_id IN ($idPlaceholders)
        ORDER BY cl.created_at DESC
        LIMIT 10
    ") : null;

    $recentCheckins = [];
    if ($rcStmt) {
        $rcStmt->execute($assignedIds);
        $recentCheckins = $rcStmt->fetchAll();
    }
} catch (Exception $e) {
    Session::flash('error', 'Error loading dashboard: ' . $e->getMessage());
}

$pageTitle = 'Dashboard';
$basePath = Utils::basePath();

ob_start();
?>

<div class="dashboard-grid">
    <!-- Stats Cards -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-content">
                <h3><?= $stats['total_events'] ?></h3>
                <p>Total Events</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-content">
                <h3><?= $stats['active_events'] ?></h3>
                <p>Active Events</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-content">
                <h3><?= $stats['total_participants'] ?></h3>
                <p>Total Participants</p>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="stat-content">
                <h3><?= $stats['checked_in_today'] ?></h3>
                <p>Checked In Today</p>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <?php if (Auth::isAdmin()): ?>
    <div class="card">
        <div class="card-header">
            <h3>Quick Actions</h3>
        </div>
        <div class="card-body">
            <div class="quick-actions">
                <a href="<?= $basePath ?>/events/create.php" class="action-btn">
                    <span>Create Event</span>
                </a>
                <a href="<?= $basePath ?>/batches/upload.php" class="action-btn">
                    <span>Upload Cards</span>
                </a>
                <a href="<?= $basePath ?>/scanner/index.php" class="action-btn">
                    <span>Scan QR</span>
                </a>
                <a href="<?= $basePath ?>/reports/index.php" class="action-btn">
                    <span>Reports</span>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Events -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Events</h3>
            <?php if (Auth::isAdmin()): ?>
            <a href="<?= $basePath ?>/events/index.php" class="btn btn-sm btn-outline">View All</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($recentEvents)): ?>
                <p class="text-muted">No events yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Event Name</th>
                                <th>Date</th>
                                <th>Participants</th>
                                <th>Check-in Rate</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentEvents as $event): 
                                $totalGuests = (int) $event['total_guests'];
                                $checkedIn = (int) $event['guests_checked_in'];
                                $rate = $totalGuests > 0 ? round(($checkedIn / $totalGuests) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= Utils::escape($event['event_name']) ?></strong>
                                </td>
                                <td><?= Utils::formatDate($event['event_date']) ?></td>
                                <td><?= $event['participant_count'] ?> (<?= $totalGuests ?> guests)</td>
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
    
    <!-- Recent Check-ins -->
    <div class="card">
        <div class="card-header">
            <h3>Recent Check-ins</h3>
        </div>
        <div class="card-body">
            <?php if (empty($recentCheckins)): ?>
                <p class="text-muted">No check-ins yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Participant</th>
                                <th>Event</th>
                                <th>Ticket Type</th>
                                <th>Guests</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentCheckins as $checkin): ?>
                            <tr>
                                <td>
                                    <strong><?= Utils::escape($checkin['name']) ?></strong><br>
                                    <small class="text-muted"><?= Utils::escape($checkin['unique_id']) ?></small>
                                </td>
                                <td><?= Utils::escape($checkin['event_name']) ?></td>
                                <td><span class="badge badge-info"><?= Utils::escape($checkin['ticket_type']) ?></span></td>
                                <td><?= $checkin['guests_this_checkin'] ?></td>
                                <td><?= Utils::formatDateTime($checkin['created_at']) ?></td>
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
require_once __DIR__ . '/../templates/layout.php';
