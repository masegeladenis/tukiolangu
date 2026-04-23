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

// Get active events the current user is allowed to scan
$assignedIds = Auth::getAssignedEventIds();

if (empty($assignedIds)) {
    $events = [];
} else {
    $placeholders = implode(',', array_fill(0, count($assignedIds), '?'));
    $stmt = $db->getConnection()->prepare(
        "SELECT id, event_name, event_code FROM events WHERE status = 'active' AND id IN ($placeholders) ORDER BY event_date DESC"
    );
    $stmt->execute($assignedIds);
    $events = $stmt->fetchAll();
}

$pageTitle = 'QR Code Scanner';
$basePath = Utils::basePath();

$extraCss = '
<style>
.scanner-page {
    max-width: 500px;
    margin: 0 auto;
}

.scanner-section {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 32px 24px;
    margin-bottom: 16px;
    text-align: center;
}

.scanner-section h2 {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 8px 0;
}

.scanner-section p {
    font-size: 14px;
    color: #6b7280;
    margin: 0 0 24px 0;
}

#scanner-container {
    position: relative;
    max-width: 100%;
    margin: 0 auto 20px;
    border-radius: 8px;
    overflow: hidden;
    background: #f3f4f6;
    min-height: 280px;
    display: flex;
    align-items: center;
    justify-content: center;
}

#scanner-container.active {
    background: #000;
}

#video {
    width: 100%;
    display: none;
}

#video.active {
    display: block;
}

#scanner-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 200px;
    height: 200px;
    border: 2px solid #111827;
    border-radius: 4px;
    pointer-events: none;
    display: none;
}

#scanner-overlay.active {
    display: block;
}

.scanner-placeholder {
    text-align: center;
    color: #9ca3af;
}

.scanner-placeholder svg {
    width: 64px;
    height: 64px;
    margin-bottom: 12px;
    stroke: #d1d5db;
}

.scanner-controls {
    display: flex;
    gap: 12px;
    justify-content: center;
}

.divider {
    display: flex;
    align-items: center;
    gap: 16px;
    margin: 24px 0;
    color: #9ca3af;
    font-size: 13px;
}

.divider::before,
.divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: #e5e7eb;
}

.manual-entry {
    max-width: 100%;
}

.manual-entry .input-group {
    display: flex;
    gap: 8px;
}

.manual-entry .form-control {
    flex: 1;
}

.event-filter {
    margin-bottom: 24px;
}

.event-filter label {
    display: block;
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 6px;
}

.scanner-status {
    text-align: center;
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 14px;
    margin-bottom: 20px;
    display: none;
}

.scanner-status.active {
    display: block;
}

.scanner-status.scanning {
    background: #e0f2fe;
    color: #075985;
}

.scanner-status.success {
    background: #d1fae5;
    color: #065f46;
}

.scanner-status.error {
    background: #fee2e2;
    color: #991b1b;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 8px;
    max-width: 400px;
    width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 24px;
    position: relative;
}

.close-btn {
    position: absolute;
    top: 16px;
    right: 16px;
    font-size: 20px;
    cursor: pointer;
    color: #9ca3af;
    line-height: 1;
}

.close-btn:hover {
    color: #111827;
}

.status-badge {
    text-align: center;
    padding: 10px 16px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 20px;
}

.status-valid {
    background: #d1fae5;
    color: #065f46;
}

.status-partial {
    background: #fef3c7;
    color: #92400e;
}

.status-complete {
    background: #fee2e2;
    color: #991b1b;
}

.status-success {
    background: #d1fae5;
    color: #065f46;
}

.participant-info {
    text-align: center;
    margin-bottom: 20px;
}

.participant-info h2 {
    font-size: 20px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 12px 0;
}

.participant-info .detail {
    font-size: 14px;
    color: #6b7280;
    margin: 4px 0;
}

.participant-info .detail strong {
    color: #374151;
}

.guest-tracking {
    margin: 20px 0;
}

.guest-progress {
    margin-bottom: 16px;
}

.progress-bar {
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-fill {
    height: 100%;
    background: #111827;
    transition: width 0.3s;
}

.guest-count {
    text-align: center;
    font-size: 14px;
    color: #6b7280;
}

.guest-selection {
    margin: 20px 0;
}

.guest-selection h3 {
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    text-align: center;
    margin-bottom: 12px;
}

.guest-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
}

.guest-btn {
    width: 48px;
    height: 48px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 500;
    transition: all 0.15s;
}

.guest-btn:hover {
    border-color: #111827;
}

.guest-btn.selected {
    border-color: #111827;
    background: #111827;
    color: white;
}

.action-buttons {
    display: flex;
    gap: 8px;
    margin-top: 20px;
}

.action-buttons .btn {
    flex: 1;
}

.warning-message {
    text-align: center;
    padding: 16px;
    background: #fef3c7;
    border-radius: 6px;
    margin-top: 16px;
}

.warning-message p {
    margin: 0 0 4px 0;
    font-size: 14px;
    color: #92400e;
}

.warning-message small {
    color: #b45309;
}

.checkin-history {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #e5e7eb;
}

.checkin-history h4 {
    font-size: 13px;
    font-weight: 500;
    color: #6b7280;
    margin-bottom: 8px;
}

.checkin-history ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.checkin-history li {
    padding: 8px 0;
    border-bottom: 1px solid #f3f4f6;
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #374151;
}

.checkin-history li:last-child {
    border-bottom: none;
}

/* Mobile responsiveness */
@media (max-width: 576px) {
    .scanner-page {
        padding: 0 8px;
    }
    
    .scanner-section {
        padding: 24px 16px;
    }
    
    .scanner-section h2 {
        font-size: 15px;
    }
    
    .scanner-controls {
        flex-direction: column;
    }
    
    .scanner-controls .btn {
        width: 100%;
    }
    
    .manual-entry .input-group {
        flex-direction: column;
    }
    
    .manual-entry .form-control,
    .manual-entry .btn {
        width: 100%;
    }
    
    .guest-btn {
        width: 44px;
        height: 44px;
        font-size: 15px;
    }
    
    .modal-content {
        padding: 20px;
    }
    
    .participant-info h2 {
        font-size: 18px;
    }
}
</style>
';

$extraJs = '
<script src="https://unpkg.com/html5-qrcode"></script>
<script src="' . $basePath . '/js/scanner.js"></script>
';

ob_start();
?>

<div class="scanner-page">
    <div class="scanner-section">
        <div class="event-filter">
            <label for="eventFilter">Filter by Event</label>
            <select id="eventFilter" class="form-control">
                <option value="">All Active Events</option>
                <?php foreach ($events as $event): ?>
                <option value="<?= $event['event_code'] ?>">
                    <?= Utils::escape($event['event_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div id="scannerStatus" class="scanner-status scanning">
            Scanning...
        </div>
        
        <div id="scanner-container">
            <div class="scanner-placeholder" id="scannerPlaceholder">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z" />
                </svg>
                <p>Camera preview will appear here</p>
            </div>
            <video id="video" playsinline></video>
            <div id="scanner-overlay"></div>
        </div>
        
        <div class="scanner-controls">
            <button id="startButton" class="btn btn-primary">
                Start Scanner
            </button>
            <button id="stopButton" class="btn btn-outline" style="display: none;">
                Stop
            </button>
        </div>
        
        <div class="divider">or</div>
        
        <div class="manual-entry">
            <form id="manualForm">
                <div class="input-group">
                    <input type="text" id="manualId" class="form-control" placeholder="Enter ticket ID" required>
                    <button type="submit" class="btn btn-primary">Verify</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Check-in Modal -->
<div id="checkinModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal()">&times;</span>
        
        <div id="statusBadge" class="status-badge"></div>
        
        <div id="participantInfo" class="participant-info">
            <h2 id="participantName"></h2>
            <p class="detail"><strong>ID:</strong> <span id="participantId"></span></p>
            <p class="detail"><strong>Ticket:</strong> <span id="ticketType"></span></p>
            <p class="detail"><strong>Event:</strong> <span id="eventName"></span></p>
        </div>
        
        <div id="guestTracking" class="guest-tracking">
            <div class="guest-progress">
                <div class="progress-bar">
                    <div id="progressFill" class="progress-fill"></div>
                </div>
                <p class="guest-count">
                    <span id="guestsChecked">0</span> / <span id="totalGuests">0</span> guests checked in
                </p>
            </div>
        </div>
        
        <div id="guestSelection" class="guest-selection" style="display:none;">
            <h3>How many guests checking in?</h3>
            <div id="guestButtons" class="guest-buttons"></div>
        </div>
        
        <div id="checkinHistory" class="checkin-history" style="display:none;">
            <h4>Check-in History</h4>
            <ul id="historyList"></ul>
        </div>
        
        <div id="actionButtons" class="action-buttons">
            <button class="btn btn-outline" onclick="closeModal()">
                Cancel
            </button>
            <button id="confirmCheckin" class="btn btn-primary" onclick="confirmCheckin()">
                Confirm Check-in
            </button>
        </div>
        
        <div id="alreadyCheckedMsg" class="warning-message" style="display:none;">
            <p>All guests already checked in</p>
            <small>Last check-in: <span id="lastCheckinTime"></span></small>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
