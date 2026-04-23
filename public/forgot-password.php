<?php
/**
 * Forgot Password Page
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;
use App\Services\EmailService;

Session::start();

// If already logged in, redirect to dashboard
if (Auth::check()) {
    Utils::redirect('/tukioqrcode/public/dashboard.php');
}

$error = '';
$success = '';
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        $db = Connection::getInstance();
        
        // Find user by email
        $user = $db->queryOne(
            "SELECT id, username, email, full_name FROM users WHERE email = :email AND is_active = 1",
            ['email' => $email]
        );
        
        if ($user) {
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            
            // Delete any existing tokens for this user
            $db->execute(
                "DELETE FROM password_resets WHERE user_id = :user_id",
                ['user_id' => $user['id']]
            );
            
            // Store the reset token - use MySQL's DATE_ADD for consistent timezone
            $db->execute(
                "INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))",
                [
                    'user_id' => $user['id'],
                    'token' => hash('sha256', $token)
                ]
            );
            
            // Generate reset link
            $resetLink = APP_URL . '/public/reset-password.php?token=' . $token;
            
            // Send password reset email using PHPMailer
            $emailService = new EmailService();
            $result = $emailService->sendPasswordResetEmail(
                $user['email'],
                $user['full_name'],
                $resetLink
            );
            
            if ($result['success']) {
                $success = 'A password reset link has been sent to your email address. Please check your inbox.';
                $resetLink = ''; // Don't show link when email is sent successfully
            } else {
                // Email failed - show link as fallback (for development)
                $error = 'Failed to send email. Please try again or contact support.';
                // Uncomment below line for debugging
                // $error .= ' Debug: ' . $result['message'];
            }
        } else {
            // Don't reveal if email exists or not (security)
            $success = 'If an account with that email exists, a password reset link has been sent.';
        }
    }
}

$basePath = Utils::basePath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#6366f1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Forgot Password - Tukio Langu App</title>
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $basePath ?>/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $basePath ?>/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $basePath ?>/favicon/favicon-16x16.png">
    <link rel="manifest" href="<?= $basePath ?>/favicon/site.webmanifest">
    <link rel="shortcut icon" href="<?= $basePath ?>/favicon/favicon.ico">
    <link rel="stylesheet" href="<?= $basePath ?>/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-key"></i>
                <h1>Forgot Password</h1>
                <p>Enter your email to reset your password</p>
            </div>
            
            <form method="POST" action="" class="login-form">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= Utils::escape($error) ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= Utils::escape($success) ?></span>
                </div>
                
                <?php if (!empty($resetLink)): ?>
                <div class="alert alert-info" style="margin-top: 10px; word-break: break-all;">
                    <i class="fas fa-link"></i>
                    <span><strong>Reset Link (Dev Mode):</strong><br>
                    <a href="<?= Utils::escape($resetLink) ?>"><?= Utils::escape($resetLink) ?></a></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <?= Utils::csrfField() ?>
                
                <div class="form-group">
                    <label for="email">
                        <i class="fas fa-envelope"></i>
                        Email Address
                    </label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        required 
                        autofocus
                        placeholder="Enter your email address"
                        autocomplete="email"
                    >
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-paper-plane"></i>
                    Send Reset Link
                </button>
            </form>
            
            <div class="login-footer">
                <p><a href="<?= $basePath ?>/login.php"><i class="fas fa-arrow-left"></i> Back to Login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
