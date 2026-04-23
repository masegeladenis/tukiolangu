<?php
/**
 * Batch Processing API with Streaming Progress
 */
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

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Disable output buffering
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
set_time_limit(0);

Session::start();

// Check authentication
if (!Auth::check() || !Auth::isAdmin()) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Unauthorized']) . "\n\n";
    flush();
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$batchId = (int) ($input['batch_id'] ?? 0);

if ($batchId <= 0) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Invalid batch ID']) . "\n\n";
    flush();
    exit;
}

$db = Connection::getInstance();

// Get batch details
$batch = $db->queryOne("
    SELECT b.*, e.event_name, e.event_code
    FROM batches b
    JOIN events e ON b.event_id = e.id
    WHERE b.id = :id
", ['id' => $batchId]);

if (!$batch) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Batch not found']) . "\n\n";
    flush();
    exit;
}

if ($batch['status'] === 'completed') {
    echo "data: " . json_encode(['type' => 'complete', 'processed' => $batch['processed'], 'total' => $batch['total_cards']]) . "\n\n";
    flush();
    exit;
}

if ($batch['status'] === 'processing') {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Batch is already being processed']) . "\n\n";
    flush();
    exit;
}

try {
    // Update status to processing
    $db->execute("UPDATE batches SET status = 'processing' WHERE id = :id", ['id' => $batchId]);

    // --- Setup phase: validate everything before touching any row ---

    // 1. Excel file must exist
    if (!file_exists($batch['excel_path'])) {
        throw new \Exception("Excel file not found on disk. It may have been deleted. Path: " . basename($batch['excel_path']));
    }

    // 2. Design image must exist
    if (!file_exists($batch['design_path'])) {
        throw new \Exception("Card design template not found on disk. Please re-upload the batch with a valid design image. Path: " . basename($batch['design_path']));
    }

    $excelReader   = new ExcelReader();
    $qrGenerator   = new QRCodeGenerator($batch['qr_size']);
    $imageProcessor = new ImageProcessor();
    $imageProcessor->setQRPosition($batch['qr_position'])->setQRSize($batch['qr_size']);

    // Read Excel data — throws with a clear message if headers are missing / file corrupt
    try {
        $participants = $excelReader->read($batch['excel_path']);
    } catch (\Exception $e) {
        throw new \Exception("Could not read Excel file — " . $e->getMessage() . ". Make sure the file is a valid .xlsx/.xls and has a 'name' column header.");
    }

    $total = count($participants);

    if ($total === 0) {
        throw new \Exception("The Excel file has no data rows. Add participant rows below the header row and re-upload.");
    }

    // Send initial info so the UI knows the total
    echo "data: " . json_encode(['type' => 'start', 'total' => $total]) . "\n\n";
    flush();

    $processed = 0;
    $errors    = [];

    foreach ($participants as $index => $participant) {
        $rowNum = $index + 2; // 1-based, row 1 is header
        $rowLabel = "Row {$rowNum}" . (!empty($participant['name']) ? " ({$participant['name']})" : '');

        try {
            // Generate unique ID with event code
            $uniqueId = Utils::generateUniqueId($batch['event_code']);

            while ($db->queryOne("SELECT id FROM participants WHERE unique_id = :uid", ['uid' => $uniqueId])) {
                $uniqueId = Utils::generateUniqueId($batch['event_code']);
            }

            // Prepare QR data
            $qrData = [
                'id'         => $uniqueId,
                'name'       => $participant['name'],
                'event'      => $batch['event_name'],
                'event_code' => $batch['event_code'],
                'ticket'     => $participant['ticket_type'],
                'guests'     => $participant['guests'],
                'valid'      => true
            ];

            // Generate QR code
            try {
                $qrImage = $qrGenerator->generateGdImage($qrData);
            } catch (\Exception $e) {
                throw new \Exception("QR code generation failed: " . $e->getMessage());
            }

            // Overlay QR on design
            try {
                $cardPath = $imageProcessor->overlayQRCode(
                    $batch['design_path'],
                    $qrImage,
                    $uniqueId,
                    [
                        'ticket_type' => $participant['ticket_type'],
                        'name'        => $participant['name'],
                        'guests'      => $participant['guests'],
                    ]
                );
            } catch (\Exception $e) {
                throw new \Exception("Card image generation failed: " . $e->getMessage() . ". Check that the design image is a valid PNG or JPEG.");
            }

            // Insert participant record
            try {
                $db->insert("
                    INSERT INTO participants (
                        batch_id, event_id, name, email, phone, organization,
                        unique_id, ticket_type, total_guests, guests_remaining,
                        qr_data, card_output_path, status
                    ) VALUES (
                        :batch_id, :event_id, :name, :email, :phone, :organization,
                        :unique_id, :ticket_type, :total_guests, :guests_remaining,
                        :qr_data, :card_output_path, 'active'
                    )
                ", [
                    'batch_id'         => $batchId,
                    'event_id'         => $batch['event_id'],
                    'name'             => $participant['name'],
                    'email'            => $participant['email'],
                    'phone'            => $participant['phone'],
                    'organization'     => $participant['organization'],
                    'unique_id'        => $uniqueId,
                    'ticket_type'      => $participant['ticket_type'],
                    'total_guests'     => $participant['guests'],
                    'guests_remaining' => $participant['guests'],
                    'qr_data'          => json_encode($qrData),
                    'card_output_path' => $cardPath
                ]);
            } catch (\Exception $e) {
                throw new \Exception("Database insert failed: " . $e->getMessage());
            }

            $processed++;

            echo "data: " . json_encode([
                'type'     => 'progress',
                'current'  => $processed,
                'total'    => $total,
                'name'     => $participant['name'],
                'uniqueId' => $uniqueId
            ]) . "\n\n";
            flush();

        } catch (\Exception $e) {
            $reason = $e->getMessage();
            $errors[] = "{$rowLabel}: {$reason}";

            // Send real-time row-level error so the UI can display it immediately
            echo "data: " . json_encode([
                'type'    => 'row_error',
                'row'     => $rowNum,
                'name'    => $participant['name'] ?? 'Unknown',
                'reason'  => $reason,
                'current' => $index + 1,
                'total'   => $total,
            ]) . "\n\n";
            flush();
        }
    }

    // Update batch status
    $db->execute("
        UPDATE batches
        SET status = 'completed', processed = :processed, completed_at = NOW()
        WHERE id = :id
    ", [
        'processed' => $processed,
        'id'        => $batchId
    ]);

    echo "data: " . json_encode([
        'type'          => 'complete',
        'processed'     => $processed,
        'total'         => $total,
        'errors'        => count($errors),
        'errorMessages' => array_slice($errors, 0, 10)
    ]) . "\n\n";
    flush();

} catch (\Exception $e) {
    $db->execute("
        UPDATE batches
        SET status = 'failed', error_message = :error
        WHERE id = :id
    ", [
        'error' => $e->getMessage(),
        'id'    => $batchId
    ]);

    echo "data: " . json_encode([
        'type'    => 'error',
        'message' => $e->getMessage()
    ]) . "\n\n";
    flush();
}
