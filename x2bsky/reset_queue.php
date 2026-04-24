<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Database;

Config::init(__DIR__ . '/.env');
$pdo = Database::getInstance();
$pdo->exec('UPDATE queue SET status = "pending"');
echo "Reset queue to pending\n";
