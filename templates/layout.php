<?php
/**
 * Main Layout Template
 */

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;

$user = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$basePath = Utils::basePath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Utils::escape($pageTitle ?? 'Tukio Langu App') ?></title>
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $basePath ?>/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $basePath ?>/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $basePath ?>/favicon/favicon-16x16.png">
    <link rel="manifest" href="<?= $basePath ?>/favicon/site.webmanifest">
    <link rel="shortcut icon" href="<?= $basePath ?>/favicon/favicon.ico">
    <link rel="stylesheet" href="<?= $basePath ?>/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if (isset($extraCss)): ?>
        <?= $extraCss ?>
    <?php endif; ?>
</head>
<body>
    <div class="app-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2><i class="fas fa-qrcode"></i> Tukio</h2>
            </div>
            
            <nav class="sidebar-nav">
                <a href="<?= $basePath ?>/dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
                
                    <?php if (Auth::hasPermission('events_view')): ?>
                    <a href="<?= $basePath ?>/events/index.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/events/') !== false ? 'active' : '' ?>">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Events</span>
                    </a>
                    <?php endif; ?>

                    <?php if (Auth::hasPermission('batches')): ?>
                    <a href="<?= $basePath ?>/batches/upload.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/batches/') !== false ? 'active' : '' ?>">
                        <i class="fas fa-upload"></i>
                        <span>Upload Cards</span>
                    </a>
                    <?php endif; ?>

                    <?php if (Auth::hasAnyPermission(['participants_view','participants_manage','participants_checkin'])): ?>
                    <a href="<?= $basePath ?>/participants/index.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/participants/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-users"></i>
                    <span>Participants</span>
                </a>
                    <?php endif; ?>

                    <?php if (Auth::hasPermission('scanner')): ?>
                    <a href="<?= $basePath ?>/scanner/index.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/scanner/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-camera"></i>
                    <span>Scanner</span>
                </a>
                    <?php endif; ?>

                    <?php if (Auth::hasPermission('reports')): ?>
                    <a href="<?= $basePath ?>/reports/index.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/reports/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
                    <?php endif; ?>

                    <?php if (Auth::hasPermission('sms')): ?>
                    <a href="<?= $basePath ?>/sms/test.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/sms/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-sms"></i>
                    <span>SMS</span>
                </a>
                    <?php endif; ?>

                    <?php if (Auth::hasPermission('users_manage')): ?>
                    <a href="<?= $basePath ?>/users/index.php" class="nav-item <?= strpos($_SERVER['REQUEST_URI'], '/users/') !== false ? 'active' : '' ?>">
                    <i class="fas fa-user-cog"></i>
                    <span>Users</span>
                </a>
                    <?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <span><?= Utils::escape($user['full_name'] ?? 'User') ?></span>
                </div>
                <a href="<?= $basePath ?>/logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </aside>
        
        <!-- Sidebar Overlay for Mobile -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <header class="top-header">
                <div class="header-left">
                    <button id="sidebarToggle" class="sidebar-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1><?= Utils::escape($pageTitle ?? 'Dashboard') ?></h1>
                </div>
                <div class="header-right">
                    <span class="header-date"><?= date('l, F j, Y') ?></span>
                </div>
            </header>

            <!-- Flash Messages -->
            <?php if (Session::hasFlash('success')): ?>
            <div class="alert alert-success">
                <?= Utils::escape(Session::getFlash('success')) ?>
            </div>
            <?php endif; ?>
            
            <?php if (Session::hasFlash('error')): ?>
            <div class="alert alert-danger">
                <?= Utils::escape(Session::getFlash('error')) ?>
            </div>
            <?php endif; ?>
            
            <?php if (Session::hasFlash('warning')): ?>
            <div class="alert alert-warning">
                <?= Utils::escape(Session::getFlash('warning')) ?>
            </div>
            <?php endif; ?>

            <!-- Page Content -->
            <div class="content-wrapper">
                <?= $content ?? '' ?>
            </div>
        </main>
    </div>

    <script>
        // Global base path for JavaScript API calls
        window.APP_BASE_PATH = '<?= $basePath ?>';
    </script>
    <script src="<?= $basePath ?>/js/script.js"></script>
    <?php if (isset($extraJs)): ?>
        <?= $extraJs ?>
    <?php endif; ?>
</body>
</html>
