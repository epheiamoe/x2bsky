#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Logger;
use X2BSky\Queue\QueueManager;
use X2BSky\Worker\SyncEngine;

Config::init(__DIR__ . '/.env');
QueueManager::init();

$engine = new SyncEngine();
$running = true;

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$running) {
        Logger::info('Received SIGTERM, shutting down gracefully...');
        $running = false;
    });
    pcntl_signal(SIGINT, function () use (&$running) {
        Logger::info('Received SIGINT, shutting down gracefully...');
        $running = false;
    });
}

Logger::info('Worker started', ['pid' => getmypid()]);

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    $item = QueueManager::dequeue();

    if (!$item) {
        sleep(2);
        continue;
    }

    Logger::info('Dequeued item', [
        'job_id' => $item['job_id'] ?? 'unknown',
        'x_post_id' => $item['x_post_id'] ?? 'unknown',
        'attempt' => $item['attempts'] ?? 0,
    ]);

    $success = $engine->processQueueItem($item);

    if (isset($item['id'])) {
        if ($success) {
            QueueManager::markComplete((int) $item['id']);
        } else {
            $maxAttempts = $item['max_attempts'] ?? 5;
            $attempts = $item['attempts'] ?? 0;

            if ($attempts >= $maxAttempts) {
                QueueManager::markFailed((int) $item['id'], 'Max attempts reached');
                Logger::error('Max retry attempts reached', ['x_post_id' => $item['x_post_id']]);
            } else {
                QueueManager::markFailed((int) $item['id'], 'Processing failed');
            }
        }
    }

    $engine->updateJobProgress($item['job_id'] ?? '', 1, $success);

    $pdo = \X2BSky\Database::getInstance();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM queue WHERE job_id = ? AND status IN ("pending", "processing")');
    $stmt->execute([$item['job_id']]);
    $remaining = (int) $stmt->fetchColumn();

    if ($remaining === 0) {
        $engine->completeJob($item['job_id']);
        Logger::info('Job completed', ['job_id' => $item['job_id']]);
    }
}

Logger::info('Worker stopped');
