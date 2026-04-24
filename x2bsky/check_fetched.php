<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Database;

Config::init(__DIR__ . '/.env');
Database::getInstance();
$pdo = Database::getInstance();
$stmt = $pdo->query('SELECT COUNT(*) FROM fetched_posts');
echo "Total: " . $stmt->fetchColumn() . "\n";
