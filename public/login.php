<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;

Session::start();

// If already logged in, redirect to dashboard
if (Auth::check()) {
    Utils::redirect('/tukioqrcode/public/dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        if (Auth::attempt($username, $password)) {
            Utils::redirect('/tukioqrcode/public/dashboard.php');
        } else {
            $error = 'Invalid username or password';
        }
    }
}

require_once __DIR__ . '/../templates/login.php';
