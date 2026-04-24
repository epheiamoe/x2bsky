#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Logger;
use X2BSky\Settings;
use X2BSky\Queue\QueueManager;
use X2BSky\Worker\SyncEngine;

Config::init(__DIR__ . '/.env');
Settings::initDefaults();
QueueManager::init();

if (!Settings::get('cron_enabled', false)) {
    Logger::info('Cron sync skipped - disabled in settings');
    echo "Cron sync is disabled\n";
    exit(0);
}

$engine = new SyncEngine();
$count = (int) Settings::get('sync_count', 10);

Logger::info('Cron sync triggered', ['count' => $count]);

try {
    $result = $engine->sync($count);
    Logger::info('Cron sync completed', ['result' => $result]);
    echo "Sync completed: {$result['status']}\n";
} catch (\Throwable $e) {
    Logger::error('Cron sync failed', ['error' => $e->getMessage()]);
    echo "Sync failed: {$e->getMessage()}\n";
    exit(1);
}
