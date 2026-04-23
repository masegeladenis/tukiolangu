<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;
use App\Services\ExcelReader;

Session::start();
Auth::requireAdmin();

$db = Connection::getInstance();
$pageTitle = 'Upload Card Design & Participant List';

// Get active events
$events = $db->query("
    SELECT id, event_name, event_code, event_date
    FROM events
    WHERE status IN ('draft', 'active')
    ORDER BY created_at DESC
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $qrPosition = $_POST['qr_position'] ?? 'bottom-right';
        $qrSize = (int) ($_POST['qr_size'] ?? 150);
        
        if ($eventId <= 0) {
            throw new Exception('Please select an event');
        }
        
        // Validate design upload
        if (empty($_FILES['design']['name'])) {
            throw new Exception('Please upload a card design');
        }
        
        $designFile = $_FILES['design'];
        if ($designFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(Utils::getUploadError($designFile['error']));
        }
        
        if (!in_array($designFile['type'], ALLOWED_IMAGE_TYPES)) {
            throw new Exception('Design must be JPG or PNG image');
        }
        
        if ($designFile['size'] > MAX_IMAGE_SIZE) {
            throw new Exception('Design file is too large (max 10MB)');
        }
        
        // Validate Excel upload
        if (empty($_FILES['excel']['name'])) {
            throw new Exception('Please upload participant list (Excel)');
        }
        
        $excelFile = $_FILES['excel'];
        if ($excelFile['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(Utils::getUploadError($excelFile['error']));
        }
        
        if (!in_array($excelFile['type'], ALLOWED_EXCEL_TYPES)) {
            throw new Exception('Participant list must be Excel or CSV file');
        }
        
        if ($excelFile['size'] > MAX_EXCEL_SIZE) {
            throw new Exception('Excel file is too large (max 5MB)');
        }
        
        // Save design file
        $designFilename = uniqid('design_') . '_' . Utils::sanitizeFilename($designFile['name']);
        $designPath = DESIGNS_PATH . '/' . $designFilename;
        
        if (!move_uploaded_file($designFile['tmp_name'], $designPath)) {
            throw new Exception('Failed to save design file');
        }
        
        // Save Excel file
        $excelFilename = uniqid('excel_') . '_' . Utils::sanitizeFilename($excelFile['name']);
        $excelPath = EXCEL_PATH . '/' . $excelFilename;
        
        if (!move_uploaded_file($excelFile['tmp_name'], $excelPath)) {
            unlink($designPath); // Clean up design file
            throw new Exception('Failed to save Excel file');
        }
        
        // Validate Excel content
        $excelReader = new ExcelReader();
        $rowCount = $excelReader->countRows($excelPath);
        
        if ($rowCount === 0) {
            unlink($designPath);
            unlink($excelPath);
            throw new Exception('Excel file contains no data rows');
        }
        
        // Create batch record
        $batchId = $db->insert("
            INSERT INTO batches (
                event_id, batch_name, design_path, excel_path,
                qr_position, qr_size, total_cards, status, created_by
            ) VALUES (
                :event_id, :batch_name, :design_path, :excel_path,
                :qr_position, :qr_size, :total_cards, 'pending', :created_by
            )
        ", [
            'event_id' => $eventId,
            'batch_name' => 'Batch ' . date('Y-m-d H:i:s'),
            'design_path' => $designPath,
            'excel_path' => $excelPath,
            'qr_position' => $qrPosition,
            'qr_size' => $qrSize,
            'total_cards' => $rowCount,
            'created_by' => Auth::id()
        ]);
        
        Session::flash('success', "Batch created successfully! {$rowCount} participants will be processed.");
        Utils::redirect("/batches/process.php?id={$batchId}");
        
    } catch (Exception $e) {
        Session::flash('error', $e->getMessage());
    }
}

$basePath = Utils::basePath();
ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3>Upload Files</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <?= Utils::csrfField() ?>
            
            <!-- Event Selection -->
            <div class="form-group">
                <label for="event_id">Select Event *</label>
                <select name="event_id" id="event_id" class="form-control" required>
                    <option value="">-- Choose Event --</option>
                    <?php foreach ($events as $event): ?>
                    <option value="<?= $event['id'] ?>">
                        <?= Utils::escape($event['event_name']) ?> 
                        (<?= Utils::formatDate($event['event_date']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($events)): ?>
                <small class="text-muted">
                    <a href="<?= $basePath ?>/events/create.php">Create an event first</a>
                </small>
                <?php endif; ?>
            </div>
            
            <!-- Design Upload -->
            <div class="form-group">
                <label for="design">Card Design (PNG/JPG) *</label>
                <input 
                    type="file" 
                    name="design" 
                    id="design" 
                    class="form-control" 
                    accept=".png,.jpg,.jpeg,image/png,image/jpeg"
                    required
                >
                <small class="text-muted">Upload the base card design. QR code will be added to this image. Max 10MB.</small>
                <div id="designPreview" class="file-preview"></div>
            </div>
            
            <!-- Excel Upload -->
            <div class="form-group">
                <label for="excel">Participant List (Excel/CSV) *</label>
                <input 
                    type="file" 
                    name="excel" 
                    id="excel" 
                    class="form-control" 
                    accept=".xlsx,.xls,.csv"
                    required
                >
                <small class="text-muted">
                    Excel must contain columns: Name, Email, Phone, Ticket Type, Guests.
                    <a href="<?= $basePath ?>/download_template.php">Download sample template</a>
                </small>
            </div>
            
            <!-- QR Position -->
            <div class="form-group">
                <label for="qr_position">QR Code Position</label>
                <select name="qr_position" id="qr_position" class="form-control">
                    <option value="bottom-right">Bottom Right</option>
                    <option value="bottom-left">Bottom Left</option>
                    <option value="top-right">Top Right</option>
                    <option value="top-left">Top Left</option>
                </select>
            </div>
            
            <!-- QR Size -->
            <div class="form-group">
                <label for="qr_size">QR Code Size (pixels)</label>
                <input 
                    type="number" 
                    name="qr_size" 
                    id="qr_size" 
                    class="form-control" 
                    value="150" 
                    min="100" 
                    max="300"
                >
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg" <?= empty($events) ? 'disabled' : '' ?>>
                    <i class="fas fa-check"></i> Upload & Process
                </button>
                <a href="<?= $basePath ?>/dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Preview design image
document.getElementById('design').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('designPreview');
            preview.innerHTML = '<img src="' + e.target.result + '" alt="Design Preview" style="max-width: 100%; max-height: 300px; margin-top: 10px;">';
        };
        reader.readAsDataURL(file);
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
