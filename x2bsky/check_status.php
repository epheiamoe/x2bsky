<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Database;

Config::init(__DIR__ . '/.env');
$pdo = Database::getInstance();

$stmt = $pdo->query('SELECT status, COUNT(*) as cnt FROM synced_posts GROUP BY status');
echo "Synced posts status:\n";
while ($row = $stmt->fetch()) {
    echo "  {$row['status']}: {$row['cnt']}\n";
}
