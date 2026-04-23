<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();
Auth::require();

$db = Connection::getInstance();
$pageTitle = 'Participants';
$isAdmin = Auth::isAdmin();

// Scope all queries to events the current user can access
$assignedIds = Auth::getAssignedEventIds();

$eventId = (int) ($_GET['event_id'] ?? 0);
// If a scanner requests a specific event they can't access, ignore it
if ($eventId > 0 && !Auth::canAccessEvent($eventId)) {
    $eventId = 0;
}
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$perPage = (int) ($_GET['per_page'] ?? 100);
$page = (int) ($_GET['page'] ?? 1);

// Validate per_page
$allowedPerPage = [50, 100, 250, 500, 1000];
if (!in_array($perPage, $allowedPerPage)) {
    $perPage = 100;
}
if ($page < 1) $page = 1;

$offset = ($page - 1) * $perPage;

// Build query
$where = [];
$params = [];

// Always restrict to assigned events
if (!empty($assignedIds)) {
    if ($eventId > 0) {
        $where[] = "p.event_id = :event_id";
        $params['event_id'] = $eventId;
    } else {
        $idPlaceholders = implode(',', array_fill(0, count($assignedIds), '?'));
        $where[] = "p.event_id IN ($idPlaceholders)";
        // Note: assigned IDs are merged into params below at query time
    }
} else {
    $where[] = "1 = 0"; // no access
}

if (!empty($search)) {
    $where[] = "(p.name LIKE :search OR p.unique_id LIKE :search2 OR p.email LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}

if ($status === 'checked') {
    $where[] = "p.is_fully_checked_in = 1";
} elseif ($status === 'partial') {
    $where[] = "p.guests_checked_in > 0 AND p.is_fully_checked_in = 0";
} elseif ($status === 'pending') {
    $where[] = "p.guests_checked_in = 0";
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Build positional params for IN clause when no specific event selected
$buildParams = function() use ($eventId, $params, $assignedIds) {
    if ($eventId > 0 || empty($assignedIds)) {
        return array_values($params);
    }
    // Merge search/status named params (as values) with the positional IN ids
    return array_merge(array_values($params), $assignedIds);
};

$pdo = $db->getConnection();

$countStmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM participants p
    JOIN events e ON p.event_id = e.id
    {$whereClause}
");
$countStmt->execute($buildParams());
$totalCount = (int) ($countStmt->fetch()['total'] ?? 0);

$totalPages = ceil($totalCount / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;

$listStmt = $pdo->prepare("
    SELECT p.*, e.event_name,
           (SELECT COUNT(*) FROM whatsapp_logs wl WHERE wl.participant_id = p.id) as whatsapp_sent
    FROM participants p
    JOIN events e ON p.event_id = e.id
    {$whereClause}
    ORDER BY p.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($buildParams());
$participants = $listStmt->fetchAll();

// Get events for filter — scoped to assigned events
$eventsForFilter = !empty($assignedIds)
    ? $db->getConnection()->query(
        "SELECT id, event_name FROM events WHERE id IN (" . implode(',', $assignedIds) . ") ORDER BY created_at DESC"
      )->fetchAll()
    : [];

$basePath = Utils::basePath();
ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>All Participants</h3>
        <?php if ($isAdmin): ?>
        <a href="add.php<?= $eventId > 0 ? '?event_id=' . $eventId : '' ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Participant
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <form method="GET" class="filter-form" id="filterForm">
            <select name="event_id" id="eventFilter" class="form-control">
                <option value="">All Events</option>
                <?php foreach ($eventsForFilter as $event): ?>
                <option value="<?= $event['id'] ?>" <?= $eventId == $event['id'] ? 'selected' : '' ?>>
                    <?= Utils::escape($event['event_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <select name="status" id="statusFilter" class="form-control">
                <option value="">All Status</option>
                <option value="checked" <?= $status === 'checked' ? 'selected' : '' ?>>Checked In</option>
                <option value="partial" <?= $status === 'partial' ? 'selected' : '' ?>>Partial</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
            
            <select name="per_page" id="perPageFilter" class="form-control" style="width: auto;">
                <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50 per page</option>
                <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100 per page</option>
                <option value="250" <?= $perPage == 250 ? 'selected' : '' ?>>250 per page</option>
                <option value="500" <?= $perPage == 500 ? 'selected' : '' ?>>500 per page</option>
                <option value="1000" <?= $perPage == 1000 ? 'selected' : '' ?>>1000 per page</option>
            </select>
            
            <div style="position: relative; flex: 1;">
                <input 
                    type="text" 
                    name="search" 
                    id="searchInput"
                    class="form-control" 
                    placeholder="Search name, ID, email, phone..."
                    value="<?= Utils::escape($search) ?>"
                    autocomplete="off"
                >
                <span id="searchSpinner" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); display: none;">
                    <i class="fas fa-spinner fa-spin" style="color: #6b7280;"></i>
                </span>
            </div>
            
            <a href="index.php" class="btn btn-outline" id="clearFilters">
                <i class="fas fa-times"></i> Clear
            </a>
        </form>
        
        <!-- Results count -->
        <div id="resultsInfo" style="margin-bottom: 16px; font-size: 14px; color: #6b7280; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                Showing <strong id="resultsCount"><?= count($participants) ?></strong> of <strong><?= $totalCount ?></strong> participant(s)
                <?php if ($totalPages > 1): ?>
                    (Page <?= $page ?> of <?= $totalPages ?>)
                <?php endif; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
            <div style="display: flex; gap: 8px; align-items: center;">
                <?php 
                // Build base URL for pagination
                $paginationParams = [];
                if ($eventId) $paginationParams['event_id'] = $eventId;
                if ($status) $paginationParams['status'] = $status;
                if ($search) $paginationParams['search'] = $search;
                if ($perPage != 100) $paginationParams['per_page'] = $perPage;
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => 1])) ?>" class="btn btn-sm btn-outline" title="First Page">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page - 1])) ?>" class="btn btn-sm btn-outline">
                        <i class="fas fa-angle-left"></i> Prev
                    </a>
                <?php endif; ?>
                
                <span style="padding: 0 8px;">Page <?= $page ?></span>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page + 1])) ?>" class="btn btn-sm btn-outline">
                        Next <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $totalPages])) ?>" class="btn btn-sm btn-outline" title="Last Page">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="table-responsive">
            <table class="table" id="participantsTable">
                <thead>
                    <tr>
                        <th>Unique ID</th>
                        <th>Name</th>
                        <th>Event</th>
                        <th>Ticket</th>
                        <th>Guests</th>
                        <th>Checked In</th>
                        <th>WhatsApp</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="participantsBody">
                    <?php if (empty($participants)): ?>
                    <tr id="noResultsRow">
                        <td colspan="9" class="text-center text-muted" style="padding: 40px;">
                            <i class="fas fa-users" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                            <p>No participants found.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($participants as $p): ?>
                    <tr data-id="<?= $p['id'] ?>">
                        <td><code><?= Utils::escape($p['unique_id']) ?></code></td>
                        <td>
                            <strong><?= Utils::escape($p['name']) ?></strong><br>
                            <small class="text-muted"><?= Utils::escape($p['email']) ?></small>
                        </td>
                        <td><?= Utils::escape($p['event_name']) ?></td>
                        <td><span class="badge badge-info"><?= Utils::escape($p['ticket_type']) ?></span></td>
                        <td><?= $p['total_guests'] ?></td>
                        <td><?= $p['guests_checked_in'] ?> / <?= $p['total_guests'] ?></td>
                        <td>
                            <?php if ($p['whatsapp_sent'] > 0): ?>
                                <span class="badge badge-success" title="WhatsApp sent"><i class="fab fa-whatsapp"></i> Sent</span>
                            <?php else: ?>
                                <span class="badge" style="background: #fef3c7; color: #92400e;" title="WhatsApp not sent"><i class="fab fa-whatsapp"></i> Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['status'] === 'cancelled'): ?>
                                <span class="badge badge-danger">Cancelled</span>
                            <?php elseif ($p['status'] === 'revoked'): ?>
                                <span class="badge badge-danger">Revoked</span>
                            <?php elseif ($p['is_fully_checked_in']): ?>
                                <span class="badge badge-success">Checked In</span>
                            <?php elseif ($p['guests_checked_in'] > 0): ?>
                                <span class="badge badge-warning">Partial</span>
                            <?php else: ?>
                                <span class="badge badge-draft">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$p['is_fully_checked_in'] && $p['status'] !== 'cancelled' && $p['status'] !== 'revoked'): ?>
                            <button type="button" class="btn btn-sm btn-success" title="Check In" onclick="openCheckinModal(<?= $p['id'] ?>, '<?= Utils::escape(addslashes($p['name'])) ?>', '<?= Utils::escape($p['unique_id']) ?>', <?= $p['total_guests'] ?>, <?= $p['guests_checked_in'] ?>)">
                                <i class="fas fa-check"></i>
                            </button>
                            <?php elseif ($p['is_fully_checked_in']): ?>
                            <button type="button" class="btn btn-sm btn-outline" style="color: #f59e0b; border-color: #f59e0b;" title="Reset Check-in" onclick="confirmResetCheckin(<?= $p['id'] ?>, '<?= Utils::escape(addslashes($p['name'])) ?>')">
                                <i class="fas fa-undo"></i>
                            </button>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                            <a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline" title="Edit Participant">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline" style="color: #dc2626; border-color: #dc2626;" title="Delete Participant" onclick="confirmDelete(<?= $p['id'] ?>, '<?= Utils::escape(addslashes($p['name'])) ?>')">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-icon danger">
                <i class="fas fa-trash-alt"></i>
            </div>
            <h3>Delete Participant</h3>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete <strong id="deleteParticipantName"></strong>?</p>
            <p style="margin-top: 12px; font-size: 13px; color: #dc2626;">
                <i class="fas fa-exclamation-triangle"></i> 
                This action cannot be undone. All check-in data will be lost.
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn" style="background: #dc2626; color: white;" onclick="executeDelete()">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<!-- Check-in Modal -->
<div class="modal-overlay" id="checkinModal">
    <div class="modal-container">
        <div class="modal-header">
            <div class="modal-icon success">
                <i class="fas fa-user-check"></i>
            </div>
            <h3>Check In Participant</h3>
        </div>
        <div class="modal-body">
            <p>Checking in <strong id="checkinParticipantName"></strong></p>
            
            <div class="guest-info-box">
                <div class="guest-stats">
                    <div class="stat-item">
                        <div class="stat-value" id="guestsCheckedInDisplay">0</div>
                        <div class="stat-label">Checked In</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="guestsRemainingDisplay">0</div>
                        <div class="stat-label">Remaining</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="guestsTotalDisplay">0</div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Number of guests to check in:</label>
                <div class="guest-counter">
                    <button type="button" class="btn btn-outline" onclick="adjustGuestCount(-1)" id="decreaseGuestsBtn">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" id="checkinGuestsCount" class="form-control" min="1" value="1" readonly>
                    <button type="button" class="btn btn-outline" onclick="adjustGuestCount(1)" id="increaseGuestsBtn">
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
            <p>Are you sure you want to reset the check-in status for <strong id="resetParticipantName"></strong>?</p>
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

<script>
const basePath = '<?= $basePath ?>';
let deleteParticipantId = null;
let searchTimeout = null;

// ===== Real-time Search Functionality =====
const searchInput = document.getElementById('searchInput');
const eventFilter = document.getElementById('eventFilter');
const statusFilter = document.getElementById('statusFilter');
const perPageFilter = document.getElementById('perPageFilter');
const searchSpinner = document.getElementById('searchSpinner');
const participantsBody = document.getElementById('participantsBody');
const resultsCount = document.getElementById('resultsCount');

// Debounce function to limit API calls
function debounce(func, delay) {
    return function(...args) {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => func.apply(this, args), delay);
    };
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Generate status badge HTML
function getStatusBadge(participant) {
    if (participant.status === 'cancelled') {
        return '<span class="badge badge-danger">Cancelled</span>';
    } else if (participant.status === 'revoked') {
        return '<span class="badge badge-danger">Revoked</span>';
    } else if (participant.is_fully_checked_in) {
        return '<span class="badge badge-success">Checked In</span>';
    } else if (participant.guests_checked_in > 0) {
        return '<span class="badge badge-warning">Partial</span>';
    } else {
        return '<span class="badge badge-draft">Pending</span>';
    }
}

// Generate check-in action buttons
function getCheckinButtons(p, escapedName) {
    const escapedUniqueId = escapeHtml(p.unique_id);
    if (!p.is_fully_checked_in && p.status !== 'cancelled' && p.status !== 'revoked') {
        return `<button type="button" class="btn btn-sm btn-success" title="Check In" onclick="openCheckinModal(${p.id}, '${escapedName}', '${escapedUniqueId}', ${p.total_guests}, ${p.guests_checked_in})">
                    <i class="fas fa-check"></i>
                </button>`;
    } else if (p.is_fully_checked_in) {
        return `<button type="button" class="btn btn-sm btn-outline" style="color: #f59e0b; border-color: #f59e0b;" title="Reset Check-in" onclick="confirmResetCheckin(${p.id}, '${escapedName}')">
                    <i class="fas fa-undo"></i>
                </button>`;
    }
    return '';
}

// Generate table row HTML
function generateRowHtml(p) {
    const escapedName = escapeHtml(p.name).replace(/'/g, "\\'");
    const whatsappBadge = p.whatsapp_sent > 0 
        ? '<span class="badge badge-success" title="WhatsApp sent"><i class="fab fa-whatsapp"></i> Sent</span>'
        : '<span class="badge" style="background: #fef3c7; color: #92400e;" title="WhatsApp not sent"><i class="fab fa-whatsapp"></i> Pending</span>';
    return `
        <tr data-id="${p.id}">
            <td><code>${escapeHtml(p.unique_id)}</code></td>
            <td>
                <strong>${escapeHtml(p.name)}</strong><br>
                <small class="text-muted">${escapeHtml(p.email)}</small>
            </td>
            <td>${escapeHtml(p.event_name)}</td>
            <td><span class="badge badge-info">${escapeHtml(p.ticket_type)}</span></td>
            <td>${p.total_guests}</td>
            <td>${p.guests_checked_in} / ${p.total_guests}</td>
            <td>${whatsappBadge}</td>
            <td>${getStatusBadge(p)}</td>
            <td>
                ${getCheckinButtons(p, escapedName)}
                <a href="edit.php?id=${p.id}" class="btn btn-sm btn-outline" title="Edit Participant">
                    <i class="fas fa-edit"></i>
                </a>
                <button type="button" class="btn btn-sm btn-outline" style="color: #dc2626; border-color: #dc2626;" title="Delete Participant" onclick="confirmDelete(${p.id}, '${escapedName}')">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
}

// Generate no results row
function generateNoResultsHtml() {
    return `
        <tr id="noResultsRow">
            <td colspan="9" class="text-center text-muted" style="padding: 40px;">
                <i class="fas fa-search" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
                <p>No participants found matching your search.</p>
            </td>
        </tr>
    `;
}

// Perform the search
async function performSearch() {
    const search = searchInput.value.trim();
    const eventId = eventFilter.value;
    const status = statusFilter.value;
    
    // Build query string
    const params = new URLSearchParams();
    if (search) params.append('search', search);
    if (eventId) params.append('event_id', eventId);
    if (status) params.append('status', status);
    
    // Show spinner
    searchSpinner.style.display = 'inline';
    
    try {
        const response = await fetch(`${basePath}/api/search-participants.php?${params.toString()}`);
        const result = await response.json();
        
        if (result.success) {
            // Update results count
            resultsCount.textContent = result.count;
            
            // Update table body
            if (result.participants.length === 0) {
                participantsBody.innerHTML = generateNoResultsHtml();
            } else {
                participantsBody.innerHTML = result.participants.map(generateRowHtml).join('');
            }
            
            // Update URL without page reload (for bookmarking/sharing)
            const newUrl = params.toString() ? `index.php?${params.toString()}` : 'index.php';
            window.history.replaceState({}, '', newUrl);
        } else {
            console.error('Search failed:', result.message);
        }
    } catch (error) {
        console.error('Search error:', error);
    } finally {
        // Hide spinner
        searchSpinner.style.display = 'none';
    }
}

// Debounced search function (300ms delay)
const debouncedSearch = debounce(performSearch, 300);

// Event listeners for real-time search
searchInput.addEventListener('input', debouncedSearch);
eventFilter.addEventListener('change', performSearch);
statusFilter.addEventListener('change', performSearch);

// Per page filter - reload the page to get correct pagination
perPageFilter.addEventListener('change', function() {
    const params = new URLSearchParams();
    if (searchInput.value.trim()) params.append('search', searchInput.value.trim());
    if (eventFilter.value) params.append('event_id', eventFilter.value);
    if (statusFilter.value) params.append('status', statusFilter.value);
    if (perPageFilter.value != 100) params.append('per_page', perPageFilter.value);
    window.location.href = 'index.php?' + params.toString();
});

// Prevent form submission (no need with real-time search)
document.getElementById('filterForm').addEventListener('submit', function(e) {
    e.preventDefault();
    performSearch();
});

// Clear filters
document.getElementById('clearFilters').addEventListener('click', function(e) {
    e.preventDefault();
    searchInput.value = '';
    eventFilter.value = '';
    statusFilter.value = '';
    perPageFilter.value = '100';
    window.location.href = 'index.php';
});

// ===== Delete Functionality =====
function confirmDelete(id, name) {
    deleteParticipantId = id;
    document.getElementById('deleteParticipantName').textContent = name;
    document.getElementById('deleteModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    document.body.style.overflow = '';
    deleteParticipantId = null;
}

// Close modal on overlay click
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});

async function executeDelete() {
    if (!deleteParticipantId) return;
    
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    
    try {
        const response = await fetch(basePath + '/api/delete-participant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ participant_id: deleteParticipantId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeDeleteModal();
            // Remove the row from the table instead of reloading
            const row = document.querySelector(`tr[data-id="${deleteParticipantId}"]`);
            if (row) {
                row.style.transition = 'opacity 0.3s, transform 0.3s';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-20px)';
                setTimeout(() => {
                    row.remove();
                    // Update count
                    const currentCount = parseInt(resultsCount.textContent) - 1;
                    resultsCount.textContent = currentCount;
                    // Show no results if empty
                    if (currentCount === 0) {
                        participantsBody.innerHTML = generateNoResultsHtml();
                    }
                }, 300);
            }
        } else {
            alert('Error: ' + result.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Delete';
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Failed to delete participant. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i> Delete';
    }
}

// ===== Check-in Functionality =====
let checkinParticipantId = null;
let checkinUniqueId = null;
let checkinTotalGuests = 0;
let checkinGuestsCheckedIn = 0;

function openCheckinModal(id, name, uniqueId, totalGuests, guestsCheckedIn) {
    checkinParticipantId = id;
    checkinUniqueId = uniqueId;
    checkinTotalGuests = totalGuests;
    checkinGuestsCheckedIn = guestsCheckedIn;
    
    const guestsRemaining = totalGuests - guestsCheckedIn;
    
    document.getElementById('checkinParticipantName').textContent = name;
    document.getElementById('checkinGuestsCount').value = guestsRemaining;
    document.getElementById('checkinGuestsCount').max = guestsRemaining;
    
    // Update the guest stats display
    document.getElementById('guestsCheckedInDisplay').textContent = guestsCheckedIn;
    document.getElementById('guestsRemainingDisplay').textContent = guestsRemaining;
    document.getElementById('guestsTotalDisplay').textContent = totalGuests;
    
    document.getElementById('checkinModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCheckinModal() {
    document.getElementById('checkinModal').classList.remove('active');
    document.body.style.overflow = '';
    checkinParticipantId = null;
    checkinUniqueId = null;
}

function adjustGuestCount(delta) {
    const input = document.getElementById('checkinGuestsCount');
    const guestsRemaining = checkinTotalGuests - checkinGuestsCheckedIn;
    let newValue = parseInt(input.value) + delta;
    if (newValue < 1) newValue = 1;
    if (newValue > guestsRemaining) newValue = guestsRemaining;
    input.value = newValue;
}

async function executeCheckin() {
    if (!checkinUniqueId) return;
    
    const guestsCount = parseInt(document.getElementById('checkinGuestsCount').value);
    const guestsRemaining = checkinTotalGuests - checkinGuestsCheckedIn;
    
    if (guestsCount < 1 || guestsCount > guestsRemaining) {
        alert('Invalid number of guests');
        return;
    }
    
    const btn = document.getElementById('checkinBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking in...';
    
    try {
        const response = await fetch(basePath + '/api/checkin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                unique_id: checkinUniqueId,
                guests_count: guestsCount,
                gate_location: 'Admin Panel',
                notes: 'Manual check-in from participant list'
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
document.getElementById('checkinModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCheckinModal();
    }
});

// ===== Reset Check-in Functionality =====
let resetParticipantId = null;

function confirmResetCheckin(id, name) {
    resetParticipantId = id;
    document.getElementById('resetParticipantName').textContent = name;
    document.getElementById('resetCheckinModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeResetCheckinModal() {
    document.getElementById('resetCheckinModal').classList.remove('active');
    document.body.style.overflow = '';
    resetParticipantId = null;
}

async function executeResetCheckin() {
    if (!resetParticipantId) return;
    
    const btn = document.getElementById('resetCheckinBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    
    try {
        const response = await fetch(basePath + '/api/reset-checkin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ participant_id: resetParticipantId })
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
document.getElementById('resetCheckinModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeResetCheckinModal();
    }
});

// Update Escape key handler to close all modals
document.removeEventListener('keydown', function(){});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
        closeCheckinModal();
        closeResetCheckinModal();
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
