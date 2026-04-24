<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;
use X2BSky\Settings;

Config::init(__DIR__ . '/.env');
Auth::requireAuth();
Settings::initDefaults();

$pdo = Database::getInstance();

$statsStmt = $pdo->query('
    SELECT
        COUNT(CASE WHEN synced = 0 AND skipped = 0 THEN 1 END) as pending,
        COUNT(CASE WHEN synced = 1 THEN 1 END) as synced,
        COUNT(CASE WHEN skipped = 1 THEN 1 END) as skipped
    FROM fetched_posts
');
$stats = $statsStmt->fetch();

$lastSyncStmt = $pdo->query('SELECT synced_at, synced_bsky_uri FROM fetched_posts WHERE synced = 1 ORDER BY synced_at DESC LIMIT 1');
$lastSync = $lastSyncStmt->fetch();

$cronEnabled = Settings::get('cron_enabled', false);
$cronInterval = Settings::get('cron_interval', 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>x2bsky - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <nav class="border-b border-slate-700 bg-slate-800/50 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-8">
                    <h1 class="text-xl font-bold text-white">x2bsky</h1>
                    <div class="hidden md:flex space-x-6">
                        <a href="index.php" class="text-white font-medium">Dashboard</a>
                        <a href="fetch.php" class="text-slate-400 hover:text-white transition">Fetch & Sync</a>
                        <a href="history.php" class="text-slate-400 hover:text-white transition">History</a>
                        <a href="settings.php" class="text-slate-400 hover:text-white transition">Settings</a>
                    </div>
                </div>
                <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-white">Dashboard</h1>
                <p class="text-slate-400 mt-1">Two-step sync: Fetch X posts, then select and sync to Bluesky</p>
            </div>
            <a href="fetch.php" class="px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-medium rounded-lg transition">
                Go to Fetch & Sync
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Pending Sync</p>
                        <p class="text-3xl font-bold text-white mt-1"><?= (int)$stats['pending'] ?></p>
                    </div>
                    <div class="w-12 h-12 bg-yellow-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Synced</p>
                        <p class="text-3xl font-bold text-green-400 mt-1"><?= (int)$stats['synced'] ?></p>
                    </div>
                    <div class="w-12 h-12 bg-green-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Skipped</p>
                        <p class="text-3xl font-bold text-slate-400 mt-1"><?= (int)$stats['skipped'] ?></p>
                    </div>
                    <div class="w-12 h-12 bg-slate-600/20 rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Auto Sync</p>
                        <p class="text-lg font-bold mt-1 <?= $cronEnabled ? 'text-green-400' : 'text-slate-400' ?>">
                            <?= $cronEnabled ? 'ON' : 'OFF' ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 <?= $cronEnabled ? 'bg-green-500/20' : 'bg-slate-600/20' ?> rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 <?= $cronEnabled ? 'text-green-400' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($lastSync): ?>
        <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
            <h2 class="text-lg font-semibold text-white mb-4">Last Sync</h2>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-slate-300"><?= date('Y-m-d H:i:s', strtotime($lastSync['synced_at'])) ?></p>
                    <?php if ($lastSync['synced_bsky_uri']): ?>
                    <a href="<?= htmlspecialchars(str_replace('at://', 'https://bsky.app/render/', $lastSync['synced_bsky_uri'])) ?>" target="_blank" class="text-blue-400 hover:text-blue-300 text-sm">
                        View on Bluesky
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="mt-8 bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
            <h2 class="text-lg font-semibold text-white mb-4">How to Use</h2>
            <div class="space-y-4 text-slate-300">
                <div class="flex items-start space-x-4">
                    <span class="flex-shrink-0 w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">1</span>
                    <div>
                        <p class="font-medium text-white">Fetch Posts</p>
                        <p class="text-sm text-slate-400">Click "Fetch & Sync" to get your latest X posts. Replies are automatically filtered out.</p>
                    </div>
                </div>
                <div class="flex items-start space-x-4">
                    <span class="flex-shrink-0 w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">2</span>
                    <div>
                        <p class="font-medium text-white">Select Posts</p>
                        <p class="text-sm text-slate-400">Check the posts you want to sync. RTs are shown but not selected by default (configurable in Settings).</p>
                    </div>
                </div>
                <div class="flex items-start space-x-4">
                    <span class="flex-shrink-0 w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold">3</span>
                    <div>
                        <p class="font-medium text-white">Sync to Bluesky</p>
                        <p class="text-sm text-slate-400">Click "Sync Selected" to post to Bluesky. Multi-post threads will be created automatically.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
