<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;
use App\Services\PDFGenerator;
use App\Services\ZipGenerator;

Session::start();
Auth::requireAdmin();

$db = Connection::getInstance();
$batchId = (int) ($_GET['id'] ?? 0);
$basePath = Utils::basePath();

if ($batchId <= 0) {
    Session::flash('error', 'Invalid batch ID');
    Utils::redirect('/dashboard.php');
}

// Get batch details
$batch = $db->queryOne("
    SELECT b.*, e.event_name
    FROM batches b
    JOIN events e ON b.event_id = e.id
    WHERE b.id = :id
", ['id' => $batchId]);

if (!$batch || $batch['status'] !== 'completed') {
    Session::flash('error', 'Batch not found or not completed');
    Utils::redirect('/dashboard.php');
}

// Get all cards for this batch
$participants = $db->query("
    SELECT * FROM participants
    WHERE batch_id = :batch_id
    ORDER BY id ASC
", ['batch_id' => $batchId]);

$pageTitle = 'Download Cards - ' . $batch['batch_name'];

// Handle download requests
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    try {
        if ($action === 'pdf') {
            $pdfGen = new PDFGenerator();
            $cardPaths = array_column($participants, 'card_output_path');
            
            $filename = 'cards_' . Utils::slugify($batch['event_name']) . '_' . date('Ymd');
            $pdfPath = $pdfGen->generateFromImages($cardPaths, $filename, [
                'title' => $batch['event_name'] . ' - Event Cards'
            ]);
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . basename($pdfPath) . '"');
            header('Content-Length: ' . filesize($pdfPath));
            readfile($pdfPath);
            exit;
            
        } elseif ($action === 'zip') {
            $zipGen = new ZipGenerator();
            $cardPaths = array_column($participants, 'card_output_path');
            
            $filename = 'cards_' . Utils::slugify($batch['event_name']) . '_' . date('Ymd');
            $zipPath = $zipGen->create($cardPaths, $filename);
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            exit;
            
        } elseif ($action === 'single' && isset($_GET['participant_id'])) {
            $participantId = (int) $_GET['participant_id'];
            $participant = $db->queryOne("
                SELECT * FROM participants WHERE id = :id AND batch_id = :batch_id
            ", ['id' => $participantId, 'batch_id' => $batchId]);
            
            if ($participant && file_exists($participant['card_output_path'])) {
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="card_' . $participant['unique_id'] . '.png"');
                header('Content-Length: ' . filesize($participant['card_output_path']));
                readfile($participant['card_output_path']);
                exit;
            }
        }
    } catch (Exception $e) {
        Session::flash('error', 'Download failed: ' . $e->getMessage());
    }
}

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>Download Cards</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-success">
            Successfully generated <?= count($participants) ?> cards!
        </div>
        
        <div class="download-options">
            <div class="download-card">
                <h4>Download as PDF</h4>
                <p>All cards in a single PDF file (one card per page)</p>
                <a href="?id=<?= $batchId ?>&action=pdf" class="btn btn-primary">
                    Download PDF
                </a>
            </div>
            
            <div class="download-card">
                <h4>Download as ZIP</h4>
                <p>All cards as individual PNG images in a ZIP file</p>
                <a href="?id=<?= $batchId ?>&action=zip" class="btn btn-outline">
                    Download ZIP
                </a>
            </div>
        </div>
        
        <hr>
        
        <h4>Individual Cards</h4>
        
        <!-- Search Box -->
        <div style="margin-bottom: 16px;">
            <div style="position: relative; max-width: 400px;">
                <input 
                    type="text" 
                    id="cardSearch" 
                    class="form-control" 
                    placeholder="Search by name, ID, or ticket type..."
                    style="padding-left: 40px;"
                    autocomplete="off"
                >
                <i class="fas fa-search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
            </div>
            <p id="searchResults" style="margin-top: 8px; font-size: 13px; color: #6b7280;">
                Showing <strong id="visibleCount"><?= count($participants) ?></strong> of <?= count($participants) ?> cards
            </p>
        </div>
        
        <div class="table-responsive">
            <table class="table" id="cardsTable">
                <thead>
                    <tr>
                        <th>Unique ID</th>
                        <th>Name</th>
                        <th>Ticket Type</th>
                        <th>Guests</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="cardsBody">
                    <?php foreach ($participants as $participant): ?>
                    <tr data-search="<?= strtolower(Utils::escape($participant['unique_id'] . ' ' . $participant['name'] . ' ' . $participant['ticket_type'])) ?>">
                        <td><code><?= Utils::escape($participant['unique_id']) ?></code></td>
                        <td><?= Utils::escape($participant['name']) ?></td>
                        <td><span class="badge badge-info"><?= Utils::escape($participant['ticket_type']) ?></span></td>
                        <td><?= $participant['total_guests'] ?></td>
                        <td>
                            <a href="?id=<?= $batchId ?>&action=single&participant_id=<?= $participant['id'] ?>" 
                               class="btn btn-sm btn-outline" title="Download Card">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- No results message -->
        <div id="noResults" style="display: none; text-align: center; padding: 40px; color: #6b7280;">
            <i class="fas fa-search" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i>
            <p>No cards found matching your search.</p>
        </div>
        
        <div class="form-actions">
            <a href="<?= $basePath ?>/dashboard.php" class="btn btn-outline">
                Back to Dashboard
            </a>
            <a href="<?= $basePath ?>/participants/index.php?event_id=<?= $batch['event_id'] ?>" class="btn btn-outline">
                View Participants
            </a>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('cardSearch');
    const cardsBody = document.getElementById('cardsBody');
    const visibleCount = document.getElementById('visibleCount');
    const noResults = document.getElementById('noResults');
    const tableWrapper = document.querySelector('.table-responsive');
    
    if (!searchInput || !cardsBody) return;
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const rows = cardsBody.querySelectorAll('tr');
        let visible = 0;
        
        rows.forEach(function(row) {
            const searchData = row.getAttribute('data-search') || '';
            
            if (searchTerm === '' || searchData.includes(searchTerm)) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update count
        visibleCount.textContent = visible;
        
        // Show/hide no results message
        if (visible === 0 && searchTerm !== '') {
            noResults.style.display = 'block';
            tableWrapper.style.display = 'none';
        } else {
            noResults.style.display = 'none';
            tableWrapper.style.display = '';
        }
    });
    
    // Focus search on page load
    searchInput.focus();
});
</script>
