<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/app.php';

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\Utils;

Session::start();
Auth::logout();

Utils::redirect('/tukioqrcode/public/login.php');
