<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;

Config::init(__DIR__ . '/.env');
Auth::logout();

header('Location: login.php');
exit;
