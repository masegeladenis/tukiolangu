<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';

use App\Helpers\Auth;
use App\Helpers\Utils;

Auth::require();

// Redirect to dashboard
Utils::redirect('/tukioqrcode/public/dashboard.php');
