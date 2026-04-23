<?php
/**
 * Reset Password Page
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;
use App\Database\Connection;

Session::start();

// If already logged in, redirect to dashboard
if (Auth::check()) {
    Utils::redirect('/tukioqrcode/public/dashboard.php');
}

$error = '';
$success = '';
$validToken = false;
$token = $_GET['token'] ?? '';

$db = Connection::getInstance();

// Validate token
if (!empty($token)) {
    $hashedToken = hash('sha256', $token);
    
    $reset = $db->queryOne(
        "SELECT pr.*, u.username, u.full_name 
         FROM password_resets pr 
         JOIN users u ON pr.user_id = u.id 
         WHERE pr.token = :token AND pr.expires_at > NOW() AND pr.used = 0",
        ['token' => $hashedToken]
    );
    
    if ($reset) {
        $validToken = true;
    } else {
        $error = 'Invalid or expired reset link. Please request a new one.';
    }
} else {
    $error = 'No reset token provided.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $error = 'Please enter a new password';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        // Update password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $db->execute(
            "UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id",
            [
                'password' => $hashedPassword,
                'id' => $reset['user_id']
            ]
        );
        
        // Mark token as used
        $db->execute(
            "UPDATE password_resets SET used = 1 WHERE id = :id",
            ['id' => $reset['id']]
        );
        
        $success = 'Your password has been reset successfully. You can now log in with your new password.';
        $validToken = false; // Hide the form
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
    <title>Reset Password - Tukio Langu App</title>
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
                <i class="fas fa-lock"></i>
                <h1>Reset Password</h1>
                <p>Enter your new password</p>
            </div>
            
            <?php if (!empty($error) && !$validToken): ?>
            <div class="login-form">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= Utils::escape($error) ?></span>
                </div>
                <a href="<?= $basePath ?>/forgot-password.php" class="btn btn-primary btn-block">
                    <i class="fas fa-redo"></i>
                    Request New Reset Link
                </a>
            </div>
            <?php elseif (!empty($success)): ?>
            <div class="login-form">
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= Utils::escape($success) ?></span>
                </div>
                <a href="<?= $basePath ?>/login.php" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i>
                    Go to Login
                </a>
            </div>
            <?php elseif ($validToken): ?>
            <form method="POST" action="" class="login-form">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= Utils::escape($error) ?></span>
                </div>
                <?php endif; ?>
                
                <div class="alert alert-info">
                    <i class="fas fa-user"></i>
                    <span>Resetting password for: <strong><?= Utils::escape($reset['full_name']) ?></strong> (<?= Utils::escape($reset['username']) ?>)</span>
                </div>
                
                <?= Utils::csrfField() ?>
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        New Password
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        autofocus
                        minlength="6"
                        placeholder="Enter new password (min 6 characters)"
                        autocomplete="new-password"
                    >
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-lock"></i>
                        Confirm Password
                    </label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        required
                        minlength="6"
                        placeholder="Confirm new password"
                        autocomplete="new-password"
                    >
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i>
                    Reset Password
                </button>
            </form>
            <?php endif; ?>
            
            <div class="login-footer">
                <p><a href="<?= $basePath ?>/login.php"><i class="fas fa-arrow-left"></i> Back to Login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
