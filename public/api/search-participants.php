<?php
/**
 * Search Participants API
 * Returns filtered participants for real-time search
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

header('Content-Type: application/json');

Session::start();

// Check if user is authenticated
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = Connection::getInstance();

// Restrict results to events the current user is allowed to access
$assignedIds = Auth::getAssignedEventIds();

$eventId = (int) ($_GET['event_id'] ?? 0);
// If requesting a specific event, verify access
if ($eventId > 0 && !Auth::canAccessEvent($eventId)) {
    echo json_encode(['success' => true, 'participants' => [], 'total' => 0]);
    exit;
}
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$perPage = (int) ($_GET['per_page'] ?? 100);

// Validate per_page
$allowedPerPage = [50, 100, 250, 500, 1000];
if (!in_array($perPage, $allowedPerPage)) {
    $perPage = 100;
}

// Build query
$where = [];
$params = [];

if ($eventId > 0) {
    $where[] = "p.event_id = :event_id";
    $params['event_id'] = $eventId;
} elseif (!empty($assignedIds)) {
    $idPlaceholders = implode(',', array_fill(0, count($assignedIds), '?'));
    $where[] = "p.event_id IN ($idPlaceholders)";
} else {
    // No assigned events — return empty
    echo json_encode(['success' => true, 'participants' => [], 'total' => 0]);
    exit;
}

if (!empty($search)) {
    $where[] = "(CAST(p.id AS CHAR) LIKE :search OR p.name LIKE :search2 OR p.unique_id LIKE :search3 OR p.email LIKE :search4 OR p.phone LIKE :search5 OR p.organization LIKE :search6)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
    $params['search4'] = "%{$search}%";
    $params['search5'] = "%{$search}%";
    $params['search6'] = "%{$search}%";
}

if ($status === 'checked') {
    $where[] = "p.is_fully_checked_in = 1";
} elseif ($status === 'partial') {
    $where[] = "p.guests_checked_in > 0 AND p.is_fully_checked_in = 0";
} elseif ($status === 'pending') {
    $where[] = "p.guests_checked_in = 0";
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Build final param list (named params first, then positional IN ids)
$buildParams = function() use ($eventId, $params, $assignedIds) {
    if ($eventId > 0 || empty($assignedIds)) {
        return array_values($params);
    }
    return array_merge(array_values($params), $assignedIds);
};

try {
    $pdo = $db->getConnection();
    $stmt = $pdo->prepare("
        SELECT p.*, e.event_name,
               (SELECT COUNT(*) FROM whatsapp_logs wl WHERE wl.participant_id = p.id) as whatsapp_sent
        FROM participants p
        JOIN events e ON p.event_id = e.id
        {$whereClause}
        ORDER BY p.created_at DESC
        LIMIT {$perPage}
    ");
    $stmt->execute($buildParams());
    $participants = $stmt->fetchAll();

    // Format the response
    $formattedParticipants = array_map(function($p) {
        return [
            'id' => $p['id'],
            'unique_id' => $p['unique_id'],
            'name' => $p['name'],
            'email' => $p['email'] ?? '',
            'phone' => $p['phone'] ?? '',
            'event_name' => $p['event_name'],
            'ticket_type' => $p['ticket_type'],
            'total_guests' => (int) $p['total_guests'],
            'guests_checked_in' => (int) $p['guests_checked_in'],
            'is_fully_checked_in' => (bool) $p['is_fully_checked_in'],
            'status' => $p['status'],
            'whatsapp_sent' => (int) ($p['whatsapp_sent'] ?? 0)
        ];
    }, $participants);

    echo json_encode([
        'success' => true,
        'participants' => $formattedParticipants,
        'count' => count($formattedParticipants)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching participants: ' . $e->getMessage()
    ]);
}
