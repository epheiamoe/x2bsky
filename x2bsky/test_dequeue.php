<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Database;

Config::init(__DIR__ . '/.env');
$pdo = Database::getInstance();

$stmt = $pdo->query('SELECT COUNT(*) FROM queue WHERE status = "pending"');
$pending = $stmt->fetchColumn();
echo "Pending queue items: $pending\n";

$stmt = $pdo->query('SELECT * FROM queue WHERE status = "pending" LIMIT 1');
$item = $stmt->fetch();
if ($item) {
    echo "Found item: " . $item['x_post_id'] . "\n";
} else {
    echo "No pending items in DB\n";
}
