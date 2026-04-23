<?php
/**
 * Login Page Template
 */

use App\Helpers\Utils;

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
    <title>Login - Tukio Langu App</title>
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
                <i class="fas fa-qrcode"></i>
                <h1>TukioLangu</h1>
                <p>Event Management System</p>
            </div>
            
            <form method="POST" action="" class="login-form">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= Utils::escape($error) ?></span>
                </div>
                <?php endif; ?>
                
                <?= Utils::csrfField() ?>
                
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i>
                        Username
                    </label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        required 
                        autofocus
                        placeholder="Enter your username"
                        autocomplete="username"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required
                        placeholder="Enter your password"
                        autocomplete="current-password"
                    >
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
                

            </form>

                <div class="forgot-password-link" style="text-align: center; margin-top: 16px;">
                    <a href="<?= $basePath ?>/forgot-password.php">
                     Forgot your password?
                    </a>
                </div>
            
        </div>
    </div>
</body>
</html>
