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

$stmt = $pdo->query('
    SELECT fp.*,
           (SELECT GROUP_CONCAT(id) FROM fetched_posts f2 WHERE f2.x_post_id = fp.x_post_id AND f2.synced = 0 AND f2.id <= fp.id) as selectable_ids
    FROM fetched_posts fp
    WHERE fp.synced = 0 AND fp.skipped = 0
    ORDER BY fp.x_created_at DESC
    LIMIT 50
');
$posts = $stmt->fetchAll();

$syncIncludeRts = Settings::get('sync_include_rts', false);
$syncIncludeQuotes = Settings::get('sync_include_quotes', true);

$filteredPosts = [];
foreach ($posts as $post) {
    if (!$syncIncludeRts && $post['is_retweet']) {
        continue;
    }
    if (!$syncIncludeQuotes && $post['is_quote']) {
        continue;
    }
    $filteredPosts[] = $post;
}

$lastSyncedId = null;
$stmt = $pdo->query('SELECT x_post_id FROM fetched_posts WHERE synced = 1 ORDER BY synced_at DESC LIMIT 1');
$lastSynced = $stmt->fetch();
if ($lastSynced) {
    $lastSyncedId = $lastSynced['x_post_id'];
}

$statsStmt = $pdo->query('
    SELECT
        COUNT(CASE WHEN synced = 0 AND skipped = 0 THEN 1 END) as pending,
        COUNT(CASE WHEN synced = 1 THEN 1 END) as synced,
        COUNT(CASE WHEN skipped = 1 THEN 1 END) as skipped
    FROM fetched_posts
');
$stats = $statsStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fetch Posts - x2bsky</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <nav class="border-b border-slate-700 bg-slate-800/50 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-8">
                    <a href="index.php" class="text-xl font-bold text-white">x2bsky</a>
                    <div class="hidden md:flex space-x-6">
                        <a href="index.php" class="text-white font-medium">Dashboard</a>
                        <a href="fetch.php" class="text-slate-400 hover:text-white transition">Fetch</a>
                        <a href="history.php" class="text-slate-400 hover:text-white transition">History</a>
                        <a href="settings.php" class="text-slate-400 hover:text-white transition">Settings</a>
                    </div>
                </div>
                <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8" x-data="fetchPage()">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-white">Fetch & Sync</h1>
                <p class="text-slate-400 mt-1">Step 1: Fetch X posts, Step 2: Select and sync to Bluesky</p>
            </div>
            <div class="flex space-x-4">
                <button @click="fetchPosts" :disabled="fetching" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 disabled:bg-slate-600 text-white rounded-lg transition">
                    <span x-show="!fetching">Fetch Posts</span>
                    <span x-show="fetching">Fetching...</span>
                </button>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-8">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Pending Sync</p>
                <p class="text-2xl font-bold text-yellow-400"><?= (int)$stats['pending'] ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Already Synced</p>
                <p class="text-2xl font-bold text-green-400"><?= (int)$stats['synced'] ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Skipped</p>
                <p class="text-2xl font-bold text-slate-400"><?= (int)$stats['skipped'] ?></p>
            </div>
        </div>

        <div x-show="fetchMessage" class="mb-6 p-4 rounded-lg" :class="fetchSuccess ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400'">
            <p x-text="fetchMessage"></p>
        </div>

        <?php if (empty($filteredPosts)): ?>
        <div class="bg-slate-800/50 backdrop-blur rounded-xl p-8 border border-slate-700 text-center">
            <p class="text-slate-400">No posts to sync. Click "Fetch Posts" to get your latest X posts.</p>
        </div>
        <?php else: ?>
        <div class="bg-slate-800/50 backdrop-blur rounded-xl border border-slate-700 overflow-hidden">
            <div class="p-4 border-b border-slate-700 flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <button @click="selectAll" class="text-sm text-blue-400 hover:text-blue-300">Select All</button>
                    <button @click="deselectAll" class="text-sm text-slate-400 hover:text-slate-300">Deselect All</button>
                </div>
                <button @click="syncSelected" :disabled="syncing || selected.length === 0" class="px-4 py-2 bg-green-600 hover:bg-green-500 disabled:bg-slate-600 text-white rounded-lg transition">
                    <span x-show="!syncing">Sync Selected (<span x-text="selected.length"></span>)</span>
                    <span x-show="syncing">Syncing...</span>
                </button>
            </div>

            <table class="w-full">
                <thead class="bg-slate-700/50">
                    <tr>
                        <th class="px-4 py-3 text-left w-12">
                            <input type="checkbox" @change="toggleAll($event)" :checked="selected.length === posts.length" class="rounded border-slate-600 bg-slate-900 text-blue-500">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Type</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Post</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Date</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Media</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-700">
                    <?php foreach ($filteredPosts as $post): ?>
                    <?php
                        $mediaItems = $post['media_json'] ? json_decode($post['media_json'], true) : [];
                        $hasMedia = !empty($mediaItems);
                    ?>
                    <tr class="hover:bg-slate-700/30" x-data="{ checked: <?= $post['x_post_id'] <= $lastSyncedId ? 'false' : 'true' ?> }">
                        <td class="px-4 py-3">
                            <input
                                type="checkbox"
                                x-model="selected"
                                :value="<?= (int)$post['id'] ?>"
                                :disabled="<?= $post['x_post_id'] <= $lastSyncedId ? 'true' : 'false' ?>"
                                class="rounded border-slate-600 bg-slate-900 text-blue-500 disabled:opacity-50"
                            >
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($post['is_retweet']): ?>
                            <span class="px-2 py-1 rounded text-xs font-medium bg-purple-500/20 text-purple-400">RT</span>
                            <?php elseif ($post['is_quote']): ?>
                            <span class="px-2 py-1 rounded text-xs font-medium bg-blue-500/20 text-blue-400">Quote</span>
                            <?php else: ?>
                            <span class="px-2 py-1 rounded text-xs font-medium bg-green-500/20 text-green-400">Original</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-sm text-slate-300 line-clamp-2"><?= htmlspecialchars(mb_substr($post['text'], 0, 120)) ?></p>
                            <?php if ($post['original_author']): ?>
                            <p class="text-xs text-slate-500 mt-1">via @<?= htmlspecialchars($post['original_author']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-slate-400 whitespace-nowrap">
                            <?= date('M d, H:i', strtotime($post['x_created_at'])) ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if ($hasMedia): ?>
                            <span class="text-green-400 text-sm">Yes (<?= count($mediaItems) ?>)</span>
                            <?php else: ?>
                            <span class="text-slate-500 text-sm">No</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div x-show="syncMessage" class="mt-6 p-4 rounded-lg" :class="syncSuccess ? 'bg-green-500/10 border border-green-500/50 text-green-400' : 'bg-red-500/10 border border-red-500/50 text-red-400'">
            <p x-text="syncMessage"></p>
        </div>

        <div x-show="syncResults.length > 0" class="mt-6 bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
            <h3 class="text-lg font-semibold text-white mb-4">Sync Results</h3>
            <div class="space-y-2">
                <template x-for="result in syncResults" :key="result.id">
                    <div class="flex items-center justify-between py-2 border-b border-slate-700 last:border-0">
                        <span class="text-sm text-slate-300" x-text="'Post #' + result.id"></span>
                        <span :class="result.status === 'success' ? 'text-green-400' : 'text-red-400'" x-text="result.status === 'success' ? 'Success' : 'Failed'"></span>
                    </div>
                </template>
            </div>
        </div>
    </main>

    <script>
        function fetchPage() {
            return {
                posts: <?= json_encode(array_map(fn($p) => [
                    'id' => (int)$p['id'],
                    'x_post_id' => $p['x_post_id'],
                    'text' => mb_substr($p['text'], 0, 120),
                    'is_retweet' => (bool)$p['is_retweet'],
                    'is_quote' => (bool)$p['is_quote'],
                    'has_media' => !empty($p['media_json'])
                ], $filteredPosts)) ?>,
                selected: [],
                fetching: false,
                syncing: false,
                fetchMessage: '',
                fetchSuccess: false,
                syncMessage: '',
                syncSuccess: false,
                syncResults: [],

                async fetchPosts() {
                    this.fetching = true;
                    this.fetchMessage = '';

                    try {
                        const response = await fetch('api/fetch.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ count: 20 })
                        });
                        const data = await response.json();

                        if (data.success) {
                            this.fetchSuccess = true;
                            this.fetchMessage = `Fetched ${data.fetched} posts. ${data.skipped} already existed.`;
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            this.fetchSuccess = false;
                            this.fetchMessage = data.error || 'Fetch failed';
                        }
                    } catch (err) {
                        this.fetchSuccess = false;
                        this.fetchMessage = 'Request failed: ' + err.message;
                    }

                    this.fetching = false;
                },

                async syncSelected() {
                    if (this.selected.length === 0) return;

                    this.syncing = true;
                    this.syncMessage = '';

                    try {
                        const response = await fetch('api/sync.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ post_ids: this.selected })
                        });
                        const data = await response.json();

                        if (data.success) {
                            this.syncSuccess = true;
                            this.syncMessage = `Synced ${data.synced} posts, ${data.failed} failed.`;
                            this.syncResults = data.results || [];
                            this.selected = [];
                            setTimeout(() => location.reload(), 2000);
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

                selectAll() {
                    this.selected = this.posts.map(p => p.id);
                },

                deselectAll() {
                    this.selected = [];
                },

                toggleAll(event) {
                    if (event.target.checked) {
                        this.selectAll();
                    } else {
                        this.deselectAll();
                    }
                }
            }
        }
    </script>
</body>
</html>
