<?php
/**
 * SMS Test Page
 * Test the SMS API integration
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/sms.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Services\SmsService;

Session::start();
Auth::requireAdmin();

$pageTitle = 'SMS Test';
$result = null;
$balance = null;

// Initialize SMS service
$smsService = new SmsService();

// Check balance on page load
$balanceError = null;
try {
    $balanceResult = $smsService->getBalance();
    if ($balanceResult['success'] && isset($balanceResult['data'])) {
        $balance = $balanceResult['data'];
    } else {
        $balanceError = $balanceResult['message'] ?? 'Unknown error';
    }
} catch (Exception $e) {
    $balanceError = $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_sms') {
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');
        
        if (empty($phone) || empty($message)) {
            $result = [
                'success' => false,
                'message' => 'Phone number and message are required'
            ];
        } else {
            try {
                $result = $smsService->send($phone, $message);
            } catch (Exception $e) {
                $result = [
                    'success' => false,
                    'message' => 'Error: ' . $e->getMessage()
                ];
            }
        }
    } elseif ($action === 'check_balance') {
        try {
            $result = $smsService->getBalance();
            if ($result['success']) {
                $balance = $result['data'];
            }
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
}

ob_start();
?>

<div class="page-header">
    <div>
        <h2><i class="fas fa-sms"></i> SMS Test</h2>
        <p class="text-muted">Test the SMS API integration</p>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
    
    <!-- Send SMS Card -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-paper-plane"></i> Send Test SMS</h3>
            <?php if ($smsService->isTestMode()): ?>
                <span class="badge badge-warning">Test Mode</span>
            <?php else: ?>
                <span class="badge badge-success">Live Mode</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($result && isset($_POST['action']) && $_POST['action'] === 'send_sms'): ?>
                <div class="alert <?= $result['success'] ? 'alert-success' : 'alert-danger' ?>" style="margin-bottom: 20px;">
                    <i class="fas fa-<?= $result['success'] ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= Utils::escape($result['message']) ?>
                    <?php if ($result['success'] && !empty($result['test_mode'])): ?>
                        <br><small>(Test mode - no SMS was actually sent)</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="action" value="send_sms">
                
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input 
                        type="text" 
                        id="phone" 
                        name="phone" 
                        class="form-control"
                        placeholder="e.g., 0712345678 or 255712345678"
                        value="<?= Utils::escape($_POST['phone'] ?? '') ?>"
                        required
                    >
                    <small class="text-muted">Tanzania numbers will be auto-formatted</small>
                </div>
                
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea 
                        id="message" 
                        name="message" 
                        class="form-control"
                        rows="12"
                        placeholder="Enter your test message..."
                        required
                    ><?= Utils::escape($_POST['message'] ?? "You're Invited!\n\nEvent Name\n\nDear Guest Name,\n\nEvent Details:\nDate: Saturday, December 21, 2025\nTime: 5:00 PM\nVenue: Main Hall\n\nYour Ticket:\nType: VIP\nID: TUK-2025-XXXXXX\nGuests: 2\n\nShow your QR code at the entrance for check-in.\n\nWe look forward to seeing you!\n- Tukio Langu App") ?></textarea>
                    <small class="text-muted"><span id="charCount">0</span> characters (160 chars = 1 SMS)</small>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <i class="fas fa-paper-plane"></i> Send SMS
                </button>
            </form>
        </div>
    </div>
    
    <!-- Balance & Info Card -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fas fa-wallet"></i> Account Info</h3>
        </div>
        <div class="card-body">
            
            <!-- Balance Display -->
            <div style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border-radius: 12px; padding: 24px; margin-bottom: 20px; color: white;">
                <p style="margin: 0; font-size: 13px; opacity: 0.8;">SMS Balance</p>
                <p style="margin: 8px 0 0 0; font-size: 32px; font-weight: 700;">
                    <?php 
                    $displayBalance = '--';
                    if ($balance) {
                        // Try different possible response structures
                        if (isset($balance['sms_balance'])) {
                            $displayBalance = number_format($balance['sms_balance']);
                        } elseif (isset($balance['balance'])) {
                            $displayBalance = number_format($balance['balance']);
                        } elseif (isset($balance['credit'])) {
                            $displayBalance = number_format($balance['credit']);
                        } elseif (isset($balance['credits'])) {
                            $displayBalance = number_format($balance['credits']);
                        }
                    }
                    echo $displayBalance;
                    ?>
                </p>
                <p style="margin: 4px 0 0 0; font-size: 12px; opacity: 0.7;">Credits Available</p>
            </div>
            
            <?php if ($balanceError): ?>
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-bottom: 16px; font-size: 12px;">
                <strong style="color: #991b1b;"><i class="fas fa-exclamation-circle"></i> Balance Error:</strong>
                <p style="margin: 8px 0 0 0; color: #dc2626;"><?= Utils::escape($balanceError) ?></p>
            </div>
            <?php endif; ?>
            
            <form method="POST" style="margin-bottom: 20px;">
                <input type="hidden" name="action" value="check_balance">
                <button type="submit" class="btn btn-outline" style="width: 100%;">
                    <i class="fas fa-sync-alt"></i> Refresh Balance
                </button>
            </form>
            
            <!-- API Configuration -->
            <div style="background: #f8fafc; border-radius: 8px; padding: 16px;">
                <h4 style="margin: 0 0 12px 0; font-size: 14px; color: #374151;">API Configuration</h4>
                <table style="width: 100%; font-size: 13px;">
                    <tr>
                        <td style="color: #6b7280; padding: 4px 0;">Sender ID:</td>
                        <td style="font-weight: 600; color: #111827;"><?= Utils::escape(SMS_SENDER_ID) ?></td>
                    </tr>
                    <tr>
                        <td style="color: #6b7280; padding: 4px 0;">Username:</td>
                        <td style="font-weight: 600; color: #111827;"><?= Utils::escape(SMS_USERNAME) ?></td>
                    </tr>
                    <tr>
                        <td style="color: #6b7280; padding: 4px 0;">Mode:</td>
                        <td>
                            <?php if ($smsService->isTestMode()): ?>
                                <span class="badge badge-warning">Test</span>
                            <?php else: ?>
                                <span class="badge badge-success">Live</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="color: #6b7280; padding: 4px 0;">API URL:</td>
                        <td style="font-size: 11px; color: #6b7280;"><?= Utils::escape(SMS_API_URL) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
</div>

<!-- API Response Details -->
<?php if ($result): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3><i class="fas fa-code"></i> API Response</h3>
    </div>
    <div class="card-body">
        <pre style="background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; margin: 0;"><?= Utils::escape(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    </div>
</div>
<?php endif; ?>

<script>
// Character counter
const messageInput = document.getElementById('message');
const charCount = document.getElementById('charCount');

function updateCharCount() {
    charCount.textContent = messageInput.value.length;
}

messageInput.addEventListener('input', updateCharCount);
updateCharCount();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../templates/layout.php';
