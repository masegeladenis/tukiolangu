<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;
use App\Services\ExcelReader;
use App\Services\QRCodeGenerator;
use App\Services\ImageProcessor;

Session::start();
Auth::requirePermission('batches');

$db = Connection::getInstance();
$batchId = (int) ($_GET['id'] ?? 0);
$basePath = Utils::basePath();

if ($batchId <= 0) {
    Session::flash('error', 'Invalid batch ID');
    Utils::redirect('/dashboard.php');
}

// Get batch details
$batch = $db->queryOne("
    SELECT b.*, e.event_name, e.event_code
    FROM batches b
    JOIN events e ON b.event_id = e.id
    WHERE b.id = :id
", ['id' => $batchId]);

if (!$batch) {
    Session::flash('error', 'Batch not found');
    Utils::redirect('/dashboard.php');
}

$pageTitle = 'Process Batch - ' . $batch['batch_name'];

// If batch is already completed, show results
if ($batch['status'] === 'completed') {
    Utils::redirect("/batches/download.php?id={$batchId}");
}

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>Batch Processing</h3>
    </div>
    <div class="card-body">
        <div class="batch-info">
            <div class="info-row">
                <span class="label">Event:</span>
                <span class="value"><?= Utils::escape($batch['event_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Total Cards:</span>
                <span class="value" id="totalCards"><?= $batch['total_cards'] ?></span>
            </div>
            <div class="info-row">
                <span class="label">QR Position:</span>
                <span class="value"><?= ucwords(str_replace('-', ' ', $batch['qr_position'])) ?></span>
            </div>
            <div class="info-row">
                <span class="label">QR Size:</span>
                <span class="value"><?= $batch['qr_size'] ?>px</span>
            </div>
            <div class="info-row">
                <span class="label">Status:</span>
                <span class="value"><span class="badge badge-<?= $batch['status'] ?>" id="statusBadge"><?= ucfirst($batch['status']) ?></span></span>
            </div>
        </div>
        
        <?php if ($batch['status'] === 'pending'): ?>
        <div class="alert alert-info" id="readyAlert">
            <i class="fas fa-info-circle"></i>
            Ready to process. Click the button below to start generating QR codes and cards.
            This may take a few minutes depending on the number of participants.
        </div>
        
        <!-- Progress Section (hidden initially) -->
        <div id="progressSection" style="display: none; margin: 24px 0;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span id="progressLabel">Processing...</span>
                <span id="progressCount">0 / <?= $batch['total_cards'] ?></span>
            </div>
            <div style="background: #e5e7eb; border-radius: 8px; height: 24px; overflow: hidden;">
                <div id="progressBar" style="background: linear-gradient(90deg, #10b981, #059669); height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center;">
                    <span id="progressPercent" style="color: white; font-weight: 600; font-size: 12px;"></span>
                </div>
            </div>
            <div id="currentItem" style="margin-top: 8px; color: #6b7280; font-size: 14px;"></div>
        </div>
        
        <!-- Fatal Error Section -->
        <div id="errorSection" style="display: none; margin: 16px 0;">
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <strong>Processing stopped:</strong> <span id="errorMessage"></span>
            </div>
        </div>

        <!-- Row-level Errors Log -->
        <div id="rowErrorSection" style="display: none; margin: 16px 0;">
            <div style="background: #fff7ed; border: 1px solid #f97316; border-radius: 8px; padding: 12px 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong style="color: #c2410c;"><i class="fas fa-exclamation-triangle"></i> Rows skipped due to errors (<span id="rowErrorCount">0</span>)</strong>
                    <button type="button" onclick="document.getElementById('rowErrorList').style.display = document.getElementById('rowErrorList').style.display === 'none' ? 'block' : 'none'" style="background: none; border: none; color: #c2410c; cursor: pointer; font-size: 13px;">Show / Hide</button>
                </div>
                <ul id="rowErrorList" style="margin: 0; padding-left: 18px; font-size: 13px; color: #7c2d12;"></ul>
            </div>
        </div>

        <!-- Success Section -->
        <div id="successSection" style="display: none; margin: 16px 0;">
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span id="successMessage"></span>
            </div>
        </div>
        
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <button type="button" class="btn btn-success btn-lg" id="startBtn" onclick="startProcessing()">
                <i class="fas fa-play"></i> Start Processing
            </button>
            <a href="<?= $basePath ?>/dashboard.php" class="btn btn-secondary" id="cancelBtn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <a href="<?= $basePath ?>/batches/download.php?id=<?= $batchId ?>" class="btn btn-primary" id="downloadBtn" style="display: none;">
                <i class="fas fa-download"></i> Download Cards
            </a>
        </div>
        
        <?php elseif ($batch['status'] === 'processing'): ?>
        <div class="alert alert-warning">
            <i class="fas fa-spinner fa-spin"></i>
            Processing in progress... Please wait or refresh the page.
        </div>
        
        <?php elseif ($batch['status'] === 'failed'): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            Processing failed: <?= Utils::escape($batch['error_message']) ?>
        </div>
        <a href="<?= $basePath ?>/dashboard.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <?php endif; ?>
    </div>
</div>

<script>
const batchId = <?= $batchId ?>;
const totalCards = <?= $batch['total_cards'] ?>;
const basePath = '<?= $basePath ?>';
let isProcessing = false;
let processingComplete = false;

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function startProcessing() {
    if (isProcessing) return;
    isProcessing = true;
    processingComplete = false;
    
    const startBtn = document.getElementById('startBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const readyAlert = document.getElementById('readyAlert');
    const progressSection = document.getElementById('progressSection');
    const statusBadge = document.getElementById('statusBadge');
    
    // Check if required elements exist
    if (!startBtn || !progressSection) {
        console.error('Required elements not found');
        isProcessing = false;
        return;
    }
    
    // Update UI
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    if (cancelBtn) cancelBtn.style.display = 'none';
    if (readyAlert) readyAlert.style.display = 'none';
    if (progressSection) progressSection.style.display = 'block';
    if (statusBadge) {
        statusBadge.textContent = 'Processing';
        statusBadge.className = 'badge badge-processing';
    }
    
    try {
        const response = await fetch(basePath + '/api/process-batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ batch_id: batchId })
        });
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            
            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // Keep incomplete line in buffer
            
            for (const line of lines) {
                if (line.startsWith('data: ')) {
                    try {
                        const data = JSON.parse(line.substring(6));
                        updateProgress(data);
                    } catch (e) {
                        console.log('Parse error:', e);
                    }
                }
            }
        }
        
        // Process any remaining data
        if (buffer.startsWith('data: ')) {
            try {
                const data = JSON.parse(buffer.substring(6));
                updateProgress(data);
            } catch (e) {}
        }
        
        // If processing completed successfully, ensure UI is updated
        if (processingComplete) {
            const startBtn = document.getElementById('startBtn');
            const downloadBtn = document.getElementById('downloadBtn');
            if (startBtn) startBtn.style.display = 'none';
            if (downloadBtn) downloadBtn.style.display = 'inline-flex';
        }
        
    } catch (error) {
        // Only show error if processing didn't complete successfully
        if (!processingComplete) {
            console.error('Error:', error);
            const startBtn = document.getElementById('startBtn');
            const cancelBtn = document.getElementById('cancelBtn');
            const errorSection = document.getElementById('errorSection');
            const errorMessage = document.getElementById('errorMessage');
            if (errorSection) errorSection.style.display = 'block';
            if (errorMessage) errorMessage.textContent = 'Processing failed: ' + error.message;
            if (startBtn) {
                startBtn.disabled = false;
                startBtn.innerHTML = '<i class="fas fa-redo"></i> Retry';
            }
            if (cancelBtn) cancelBtn.style.display = 'inline-flex';
        }
        isProcessing = false;
    }
}

function updateProgress(data) {
    const progressBar = document.getElementById('progressBar');
    const progressPercent = document.getElementById('progressPercent');
    const progressCount = document.getElementById('progressCount');
    const progressLabel = document.getElementById('progressLabel');
    const currentItem = document.getElementById('currentItem');
    const statusBadge = document.getElementById('statusBadge');
    const startBtn = document.getElementById('startBtn');
    const downloadBtn = document.getElementById('downloadBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const successSection = document.getElementById('successSection');
    const successMessage = document.getElementById('successMessage');
    const errorSection = document.getElementById('errorSection');
    const errorMessage = document.getElementById('errorMessage');
    
    if (data.type === 'start') {
        // Server confirmed total count before processing begins
        if (progressCount) progressCount.textContent = '0 / ' + data.total;

    } else if (data.type === 'progress') {
        const percent = Math.round((data.current / data.total) * 100);
        if (progressBar) progressBar.style.width = percent + '%';
        if (progressPercent) progressPercent.textContent = percent + '%';
        if (progressCount) progressCount.textContent = data.current + ' / ' + data.total;
        if (progressLabel) progressLabel.textContent = 'Generating cards...';
        if (data.name && currentItem) {
            currentItem.innerHTML = '<i class="fas fa-user"></i> ' + escapeHtml(data.name);
        }

    } else if (data.type === 'row_error') {
        // A single row failed — log it visibly without stopping progress
        const rowErrorSection = document.getElementById('rowErrorSection');
        const rowErrorList    = document.getElementById('rowErrorList');
        const rowErrorCount   = document.getElementById('rowErrorCount');
        if (rowErrorSection) rowErrorSection.style.display = 'block';
        if (rowErrorList) {
            const li = document.createElement('li');
            li.style.marginBottom = '4px';
            li.innerHTML = '<strong>Row ' + data.row + (data.name ? ' — ' + escapeHtml(data.name) : '') + ':</strong> ' + escapeHtml(data.reason);
            rowErrorList.appendChild(li);
        }
        if (rowErrorCount) rowErrorCount.textContent = parseInt(rowErrorCount.textContent || 0) + 1;
        // Still update the progress bar position
        if (data.total) {
            const percent = Math.round((data.current / data.total) * 100);
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressPercent) progressPercent.textContent = percent + '%';
            if (progressCount) progressCount.textContent = data.current + ' / ' + data.total;
        }

    } else if (data.type === 'complete') {
        // Mark as complete to prevent error handler from triggering
        processingComplete = true;
        isProcessing = false;
        
        if (progressBar) {
            progressBar.style.width = '100%';
            progressBar.style.background = 'linear-gradient(90deg, #10b981, #059669)';
        }
        if (progressPercent) progressPercent.textContent = '100%';
        if (progressCount) progressCount.textContent = data.processed + ' / ' + data.total;
        if (progressLabel) progressLabel.textContent = 'Complete!';
        if (currentItem) currentItem.innerHTML = '<i class="fas fa-check" style="color: #10b981;"></i> All cards generated successfully';
        
        if (statusBadge) {
            statusBadge.textContent = 'Completed';
            statusBadge.className = 'badge badge-completed';
        }
        
        if (successSection) successSection.style.display = 'block';
        const skipped = data.errors || 0;
        if (successMessage) successMessage.textContent = 'Successfully processed ' + data.processed + ' card' + (data.processed !== 1 ? 's' : '') + (skipped > 0 ? '. ' + skipped + ' row' + (skipped !== 1 ? 's' : '') + ' were skipped (see errors above).' : '.');
        
        // Hide processing button, show download button
        if (startBtn) {
            startBtn.style.display = 'none';
            startBtn.disabled = true;
        }
        if (downloadBtn) downloadBtn.style.display = 'inline-flex';
        
        // Auto-redirect after 2 seconds
        setTimeout(() => {
            window.location.href = basePath + '/batches/download.php?id=' + batchId;
        }, 2000);
        
    } else if (data.type === 'error') {
        if (progressBar) progressBar.style.background = '#dc2626';
        if (errorSection) errorSection.style.display = 'block';
        if (errorMessage) errorMessage.textContent = data.message;
        
        if (statusBadge) {
            statusBadge.textContent = 'Failed';
            statusBadge.className = 'badge badge-failed';
        }
        
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.innerHTML = '<i class="fas fa-redo"></i> Retry';
        }
        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
        isProcessing = false;
    }
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
