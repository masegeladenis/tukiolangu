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
    
    $excelReader = new ExcelReader();
    $qrGenerator = new QRCodeGenerator($batch['qr_size']);
    $imageProcessor = new ImageProcessor();
    $imageProcessor->setQRPosition($batch['qr_position'])->setQRSize($batch['qr_size']);
    
    // Read Excel data
    $participants = $excelReader->read($batch['excel_path']);
    $total = count($participants);
    
    $processed = 0;
    $errors = [];
    
    foreach ($participants as $index => $participant) {
        try {
            // Generate unique ID with event code
            $uniqueId = Utils::generateUniqueId($batch['event_code']);
            
            // Make sure unique ID doesn't exist
            while ($db->queryOne("SELECT id FROM participants WHERE unique_id = :uid", ['uid' => $uniqueId])) {
                $uniqueId = Utils::generateUniqueId($batch['event_code']);
            }
            
            // Prepare QR data
            $qrData = [
                'id' => $uniqueId,
                'name' => $participant['name'],
                'event' => $batch['event_name'],
                'event_code' => $batch['event_code'],
                'ticket' => $participant['ticket_type'],
                'guests' => $participant['guests'],
                'valid' => true
            ];
            
            // Generate QR code
            $qrImage = $qrGenerator->generateGdImage($qrData);
            
            // Overlay QR on design with participant labels
            $cardFilename = $uniqueId;
            $cardPath = $imageProcessor->overlayQRCode(
                $batch['design_path'],
                $qrImage,
                $cardFilename,
                [
                    'ticket_type' => $participant['ticket_type'],
                    'name'        => $participant['name'],
                    'guests'      => $participant['guests'],
                ]
            );
            
            // Insert participant record
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
                'batch_id' => $batchId,
                'event_id' => $batch['event_id'],
                'name' => $participant['name'],
                'email' => $participant['email'],
                'phone' => $participant['phone'],
                'organization' => $participant['organization'],
                'unique_id' => $uniqueId,
                'ticket_type' => $participant['ticket_type'],
                'total_guests' => $participant['guests'],
                'guests_remaining' => $participant['guests'],
                'qr_data' => json_encode($qrData),
                'card_output_path' => $cardPath
            ]);
            
            $processed++;
            
            // Send progress update
            echo "data: " . json_encode([
                'type' => 'progress',
                'current' => $processed,
                'total' => $total,
                'name' => $participant['name'],
                'uniqueId' => $uniqueId
            ]) . "\n\n";
            flush();
            
        } catch (Exception $e) {
            $errors[] = "Row " . ($index + 2) . ": " . $e->getMessage();
            
            // Still send progress even on error
            echo "data: " . json_encode([
                'type' => 'progress',
                'current' => $index + 1,
                'total' => $total,
                'name' => $participant['name'] ?? 'Unknown',
                'error' => $e->getMessage()
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
        'id' => $batchId
    ]);
    
    // Send completion message
    echo "data: " . json_encode([
        'type' => 'complete',
        'processed' => $processed,
        'total' => $total,
        'errors' => count($errors),
        'errorMessages' => array_slice($errors, 0, 5) // Send first 5 errors
    ]) . "\n\n";
    flush();
    
} catch (Exception $e) {
    // Update batch status to failed
    $db->execute("
        UPDATE batches 
        SET status = 'failed', error_message = :error
        WHERE id = :id
    ", [
        'error' => $e->getMessage(),
        'id' => $batchId
    ]);
    
    echo "data: " . json_encode([
        'type' => 'error',
        'message' => 'Processing failed: ' . $e->getMessage()
    ]) . "\n\n";
    flush();
}
