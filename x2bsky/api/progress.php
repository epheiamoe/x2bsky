<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Worker\SyncEngine;

header('Content-Type: application/json');

Config::init(APP_ROOT . '/.env');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$jobId = $_GET['job_id'] ?? '';

if (!$jobId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Job ID required']);
    exit;
}

try {
    $engine = new SyncEngine();
    $status = $engine->getJobStatus($jobId);

    if (!$status) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Job not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'job_id' => $status['job_id'],
        'status' => $status['status'],
        'total' => (int) $status['total_posts'],
        'processed' => (int) $status['processed_posts'],
        'successful' => (int) $status['successful_posts'],
        'failed' => (int) $status['failed_posts'],
        'error' => $status['error_message'],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
