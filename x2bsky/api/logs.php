<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;

header('Content-Type: application/json');

Config::init(APP_ROOT . '/.env');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare('SELECT * FROM logs ORDER BY created_at DESC LIMIT :limit');
    $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'logs' => $logs,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
