<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;
use X2BSky\Logger;
use X2BSky\Settings;
use X2BSky\Worker\SyncEngine;
use X2BSky\Api\XApiClient;
use X2BSky\Api\BlueskyClient;

Config::init(__DIR__ . '/.env');
Auth::requireAuth();
Settings::initDefaults();

$xClient = new XApiClient();
$bskyClient = new BlueskyClient();
$jobStatus = null;
$activeJobId = null;
$queueStats = [];

$cronEnabled = Settings::get('cron_enabled', false);
$cronInterval = Settings::get('cron_interval', 5);

$pdo = Database::getInstance();

$stmt = $pdo->query('SELECT * FROM sync_jobs ORDER BY created_at DESC LIMIT 1');
$lastJob = $stmt->fetch();

if ($lastJob && $lastJob['status'] === 'running') {
    $activeJobId = $lastJob['job_id'];
    $jobStatus = $lastJob;
}

$stmt = $pdo->query('SELECT status, COUNT(*) as count FROM queue GROUP BY status');
$queueByStatus = $stmt->fetchAll();
foreach ($queueByStatus as $row) {
    $queueStats[$row['status']] = (int) $row['count'];
}

$stmt = $pdo->query('SELECT COUNT(*) FROM synced_posts WHERE status = "synced"');
$totalSynced = (int) $stmt->fetchColumn();

$stmt = $pdo->query('SELECT COUNT(*) FROM synced_posts WHERE status = "failed"');
$totalFailed = (int) $stmt->fetchColumn();

$xStatus = 'unknown';
try {
    $xStatus = $xClient->testConnection() ? 'connected' : 'error';
} catch (\Throwable $e) {
    $xStatus = 'error';
}

$bskyStatus = 'unknown';
try {
    $bskyStatus = $bskyClient->testConnection() ? 'connected' : 'error';
} catch (\Throwable $e) {
    $bskyStatus = 'error';
}

$logs = [];
$stmt = $pdo->query('SELECT * FROM logs ORDER BY created_at DESC LIMIT 20');
$logs = $stmt->fetchAll();
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
                        <a href="history.php" class="text-slate-400 hover:text-white transition">History</a>
                        <a href="settings.php" class="text-slate-400 hover:text-white transition">Settings</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-slate-400">Connected</span>
                    <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8" x-data="dashboard()">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Total Synced</p>
                        <p class="text-3xl font-bold text-white mt-1"><?= $totalSynced ?></p>
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
                        <p class="text-slate-400 text-sm">Failed</p>
                        <p class="text-3xl font-bold text-white mt-1"><?= $totalFailed ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-500/20 rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Queue Pending</p>
                        <p class="text-3xl font-bold text-white mt-1"><?= $queueStats['pending'] ?? 0 ?></p>
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
                        <p class="text-slate-400 text-sm">X API</p>
                        <p class="text-lg font-bold mt-1 <?= $xStatus === 'connected' ? 'text-green-400' : 'text-red-400' ?>">
                            <?= strtoupper($xStatus) ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 <?= $xStatus === 'connected' ? 'bg-green-500/20' : 'bg-red-500/20' ?> rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 <?= $xStatus === 'connected' ? 'text-green-400' : 'text-red-400' ?>" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-slate-400 text-sm">Auto Sync</p>
                        <p class="text-lg font-bold mt-1 <?= $cronEnabled ? 'text-green-400' : 'text-slate-400' ?>">
                            <?= $cronEnabled ? 'ON (every ' . $cronInterval . 'm)' : 'OFF' ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 <?= $cronEnabled ? 'bg-green-500/20' : 'bg-slate-600/50' ?> rounded-full flex items-center justify-center">
                        <svg class="w-6 h-6 <?= $cronEnabled ? 'text-green-400' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <h2 class="text-lg font-semibold text-white mb-4">Manual Sync</h2>
                <form @submit.prevent="startSync">
                    <div class="mb-4">
                        <label class="block text-sm text-slate-400 mb-2">Number of recent posts</label>
                        <input
                            type="number"
                            x-model="postCount"
                            min="1"
                            max="100"
                            class="w-full px-4 py-2 bg-slate-900 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                    <button
                        type="submit"
                        :disabled="syncing"
                        class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-500 disabled:bg-slate-600 disabled:cursor-not-allowed text-white font-medium rounded-lg transition duration-200"
                    >
                        <span x-show="!syncing">Start Sync</span>
                        <span x-show="syncing">Syncing...</span>
                    </button>
                </form>

                <div x-show="syncMessage" class="mt-4 p-4 rounded-lg" :class="syncSuccess ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400'">
                    <p x-text="syncMessage"></p>
                </div>
            </div>

            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
                <h2 class="text-lg font-semibold text-white mb-4">Sync Status</h2>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Bluesky</span>
                        <span class="<?= $bskyStatus === 'connected' ? 'text-green-400' : 'text-red-400' ?>">
                            <?= strtoupper($bskyStatus) ?>
                        </span>
                    </div>
                    <?php if ($lastJob): ?>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Last Job</span>
                        <span class="text-white"><?= htmlspecialchars($lastJob['job_id']) ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Status</span>
                        <span class="px-2 py-1 rounded text-xs font-medium <?= $lastJob['status'] === 'completed' ? 'bg-green-500/20 text-green-400' : ($lastJob['status'] === 'running' ? 'bg-yellow-500/20 text-yellow-400' : 'bg-slate-600/50 text-slate-300') ?>">
                            <?= htmlspecialchars($lastJob['status']) ?>
                        </span>
                    </div>
                    <?php if ($lastJob['total_posts'] > 0): ?>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm text-slate-400 mb-1">
                            <span>Progress</span>
                            <span><?= (int)$lastJob['processed_posts'] ?> / <?= (int)$lastJob['total_posts'] ?></span>
                        </div>
                        <div class="w-full bg-slate-700 rounded-full h-2">
                            <div class="bg-blue-500 h-2 rounded-full transition-all duration-300" style="width: <?= $lastJob['total_posts'] > 0 ? ((int)$lastJob['processed_posts'] / (int)$lastJob['total_posts'] * 100) : 0 ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
            <h2 class="text-lg font-semibold text-white mb-4">Recent Logs</h2>
            <div class="space-y-2 font-mono text-sm">
                <?php foreach (array_slice($logs, 0, 10) as $log): ?>
                <div class="flex items-start space-x-3 py-2 border-b border-slate-700/50 last:border-0">
                    <span class="text-slate-500 shrink-0"><?= date('H:i:s', strtotime($log['created_at'])) ?></span>
                    <span class="px-1.5 py-0.5 rounded text-xs font-medium shrink-0
                        <?= $log['level'] === 'error' || $log['level'] === 'critical' ? 'bg-red-500/20 text-red-400' : ($log['level'] === 'warning' ? 'bg-yellow-500/20 text-yellow-400' : 'bg-slate-600/50 text-slate-300') ?>">
                        <?= strtoupper($log['level']) ?>
                    </span>
                    <span class="text-slate-300"><?= htmlspecialchars($log['message']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <p class="text-slate-500">No logs yet</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function dashboard() {
            return {
                postCount: 10,
                syncing: false,
                syncMessage: '',
                syncSuccess: false,

                async startSync() {
                    this.syncing = true;
                    this.syncMessage = '';

                    try {
                        const response = await fetch('api/sync.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({count: this.postCount})
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.syncSuccess = true;
                            this.syncMessage = `Sync started! Job ID: ${data.job_id}`;
                            this.pollProgress(data.job_id);
                        } else {
                            this.syncSuccess = false;
                            this.syncMessage = data.error || 'Sync failed';
                        }
                    } catch (err) {
                        this.syncSuccess = false;
                        this.syncMessage = 'Request failed: ' + err.message;
                    }

                    this.syncing = false;
                },

                async pollProgress(jobId) {
                    const check = async () => {
                        try {
                            const response = await fetch(`api/progress.php?job_id=${jobId}`);
                            const data = await response.json();

                            if (data.status === 'completed' || data.status === 'failed') {
                                this.syncMessage = `Job ${data.status}. Processed: ${data.processed}/${data.total}`;
                                return;
                            }

                            setTimeout(check, 2000);
                        } catch (err) {
                            console.error('Progress check failed:', err);
                        }
                    };

                    setTimeout(check, 1000);
                }
            }
        }
    </script>
</body>
</html>
