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

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = Settings::get('history_per_page', 20);
$maxPages = Settings::get('history_max_pages', 10);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->query('SELECT COUNT(*) FROM posts');
$totalPosts = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('
    SELECT p.*,
           GROUP_CONCAT(DISTINCT CONCAT(sd.platform, ":", sd.platform_post_url) SEPARATOR "|") as destinations,
           GROUP_CONCAT(DISTINCT CONCAT(pm.platform, ":", pm.original_url) SEPARATOR "|") as media,
           fp.id as fetched_posts_id,
           fp.synced as fetched_synced
    FROM posts p
    LEFT JOIN synced_destinations sd ON p.id = sd.post_id AND sd.platform = \'bluesky\'
    LEFT JOIN post_media pm ON p.id = pm.post_id
    LEFT JOIN fetched_posts fp ON p.x_post_id = fp.x_post_id
    GROUP BY p.id
    ORDER BY p.x_created_at DESC
    LIMIT :limit OFFSET :offset
');
$stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$stmt = $pdo->query('
    SELECT platform, status, COUNT(*) as count
    FROM synced_destinations
    GROUP BY platform, status
');
$statsByStatus = [];
while ($row = $stmt->fetch()) {
    $key = $row['platform'] . '_' . $row['status'];
    $statsByStatus[$key] = (int) $row['count'];
}

$totalPages = ceil($totalPosts / $perPage);

$paginationStart = max(1, $page - floor($maxPages / 2));
$paginationEnd = min($totalPages, $paginationStart + $maxPages - 1);
if ($paginationEnd - $paginationStart < $maxPages - 1) {
    $paginationStart = max(1, $paginationEnd - $maxPages + 1);
}
$paginationRange = range($paginationStart, $paginationEnd);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive - x2bsky</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <nav class="border-b border-slate-700 bg-slate-800/50 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-8">
                    <h1 class="text-xl font-bold text-white">x2bsky</h1>
                    <div class="flex space-x-6">
                        <a href="index.php" class="text-slate-400 hover:text-white transition">Dashboard</a>
                        <a href="fetch.php" class="text-slate-400 hover:text-white transition">Fetch & Sync</a>
                        <a href="archive.php" class="text-white font-medium">Archive</a>
                        <a href="settings.php" class="text-slate-400 hover:text-white transition">Settings</a>
                    </div>
                </div>
                <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8" x-data="historyPage()">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-white">Archive</h1>
            <div class="flex bg-slate-700/50 rounded-lg p-1">
                <button @click="viewMode = 'compact'"
                        :class="viewMode === 'compact' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-white'"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition">
                    Compact
                </button>
                <button @click="viewMode = 'timeline'"
                        :class="viewMode === 'timeline' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-white'"
                        class="px-4 py-2 rounded-lg text-sm font-medium transition">
                    Timeline
                </button>
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4 mb-8">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Bluesky Synced</p>
                <p class="text-2xl font-bold text-green-400"><?= $statsByStatus['bluesky_synced'] ?? 0 ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Website Synced</p>
                <p class="text-2xl font-bold text-blue-400"><?= $statsByStatus['website_synced'] ?? 0 ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Pending</p>
                <p class="text-2xl font-bold text-yellow-400"><?= $statsByStatus['bluesky_pending'] ?? 0 ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Failed</p>
                <p class="text-2xl font-bold text-red-400"><?= $statsByStatus['bluesky_failed'] ?? 0 ?></p>
            </div>
        </div>

        <?php if (empty($posts)): ?>
        <div class="text-center py-12 text-slate-500">No posts synced yet</div>
        <?php else: ?>

        <div x-show="viewMode === 'compact'">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl border border-slate-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-slate-700/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">X Post</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Bluesky</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Preview</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Media</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-300 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <?php foreach ($posts as $post):
                                $destinations = [];
                                if ($post['destinations']) {
                                    foreach (explode('|', $post['destinations']) as $dest) {
                                        [$platform, $url] = explode(':', $dest, 2);
                                        $destinations[$platform] = $url;
                                    }
                                }
                                $media = [];
                                if ($post['media']) {
                                    foreach (explode('|', $post['media']) as $m) {
                                        [$platform, $url] = explode(':', $m, 2);
                                        if (!isset($media[$platform])) $media[$platform] = [];
                                        $media[$platform][] = $url;
                                    }
                                }
                            ?>
                            <tr class="hover:bg-slate-700/30 transition">
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-slate-400">
                                    <?= $post['x_created_at'] ? date('Y-m-d H:i', strtotime($post['x_created_at'])) : 'N/A' ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm">
                                    <a href="<?= htmlspecialchars($post['x_post_url'] ?? '') ?>" target="_blank" class="text-blue-400 hover:text-blue-300">X Link</a>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm">
                                    <?php if (!empty($destinations['bluesky'])): ?>
                                    <a href="<?= htmlspecialchars($destinations['bluesky']) ?>" target="_blank" class="text-indigo-400 hover:text-indigo-300">BSKY</a>
                                    <?php elseif (!empty($post['fetched_posts_id']) && (int)$post['fetched_synced'] === 0): ?>
                                    <button @click="resyncPost(<?= (int)$post['fetched_posts_id'] ?>)" class="text-green-400 hover:text-green-300">Sync</button>
                                    <?php else: ?>
                                    <span class="text-slate-500">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-sm text-slate-300 max-w-xs truncate">
                                    <?= htmlspecialchars(mb_substr($post['text'], 0, 100)) ?>
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <?php if (!empty($media['x'])): ?>
                                    <span class="text-green-400">X: <?= count($media['x']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($media['bluesky'])): ?>
                                    <span class="text-indigo-400">BSKY: <?= count($media['bluesky']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4 text-sm">
                                    <a href="post.php?id=<?= (int)$post['id'] ?>&from=archive" class="text-blue-400 hover:text-blue-300">View</a>
                                    <button @click="confirmDelete(<?= (int)$post['id'] ?>)" class="ml-3 text-red-400 hover:text-red-300">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div x-show="viewMode === 'timeline'" class="space-y-4">
            <?php foreach ($posts as $postIndex => $post):
                $destinations = [];
                if ($post['destinations']) {
                    foreach (explode('|', $post['destinations']) as $dest) {
                        [$platform, $url] = explode(':', $dest, 2);
                        $destinations[$platform] = $url;
                    }
                }
                $media = [];
                if ($post['media']) {
                    foreach (explode('|', $post['media']) as $m) {
                        [$platform, $url] = explode(':', $m, 2);
                        if (!isset($media[$platform])) $media[$platform] = [];
                        $media[$platform][] = $url;
                    }
                }
            ?>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl border border-slate-700 p-4">
                <div class="flex items-start">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold flex-shrink-0">
                        <?= strtoupper(substr($post['original_author'] ?? 'X', 0, 1)) ?>
                    </div>
                    <div class="flex-1 min-w-0 ml-3">
                        <div class="flex items-center justify-between flex-wrap gap-1">
                            <div class="flex items-center space-x-1 flex-wrap">
                                <span class="font-semibold text-white">@<?= htmlspecialchars($post['original_author'] ?? 'unknown') ?></span>
                                <?php if ($post['is_retweet']): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-medium bg-purple-500/20 text-purple-400">RT</span>
                                <?php elseif ($post['is_quote']): ?>
                                <span class="px-2 py-0.5 rounded text-xs font-medium bg-blue-500/20 text-blue-400">Quote</span>
                                <?php endif; ?>
                                <span class="text-slate-500 text-sm">
                                    <?= $post['x_created_at'] ? date('M j, Y · H:i', strtotime($post['x_created_at'])) : '' ?>
                                </span>
                            </div>
                        </div>
                        <p class="mt-2 text-slate-100 whitespace-pre-wrap break-all"><?= htmlspecialchars($post['text']) ?></p>
                        <?php if (!empty($media['x'])): ?>
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <?php foreach ($media['x'] as $mediaIndex => $imgUrl): ?>
                            <div class="relative rounded-lg overflow-hidden bg-slate-700/50 aspect-video flex items-center justify-center"
                                 x-data="{ loaded: false, loading: false }">
                                <div x-show="!loaded" class="text-center p-2">
                                    <svg class="w-8 h-8 mx-auto text-slate-500 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <button @click="loadTimelineMedia(<?= $postIndex ?>, <?= $mediaIndex ?>, '<?= htmlspecialchars($imgUrl) ?>')"
                                            :disabled="loading"
                                            class="px-3 py-1 bg-blue-600 hover:bg-blue-500 disabled:bg-slate-600 text-white rounded text-xs transition">
                                        <span x-show="!loading">Load</span>
                                        <span x-show="loading">Loading...</span>
                                    </button>
                                </div>
                                <img x-show="loaded"
                                     @click="openTimelineLightbox(<?= $postIndex ?>, <?= $mediaIndex ?>)"
                                     :src="'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'"
                                     :data-src="'<?= htmlspecialchars($imgUrl) ?>'"
                                     class="absolute inset-0 w-full h-full object-contain cursor-pointer hover:opacity-90 loaded-img"
                                     alt="Media">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="mt-3 flex items-center justify-between border-t border-slate-700 pt-3">
                            <div class="flex items-center space-x-4">
                                <a href="<?= htmlspecialchars($post['x_post_url'] ?? '') ?>" target="_blank" class="text-blue-400 hover:text-blue-300 text-sm flex items-center font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                                    </svg>
                                    X
                                </a>
                                <?php if (!empty($destinations['bluesky'])): ?>
                                <a href="<?= htmlspecialchars($destinations['bluesky']) ?>" target="_blank" class="text-indigo-400 hover:text-indigo-300 text-sm flex items-center font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                                    </svg>
                                    BSKY
                                </a>
                                <?php elseif (!empty($post['fetched_posts_id']) && (int)$post['fetched_synced'] === 0): ?>
                                <button @click="resyncPost(<?= (int)$post['fetched_posts_id'] ?>)" class="text-green-400 hover:text-green-300 text-sm flex items-center font-medium">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                    Sync to BSKY
                                </button>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center space-x-3">
                                <a href="post.php?id=<?= (int)$post['id'] ?>&from=archive" class="text-slate-400 hover:text-white text-sm">View Details</a>
                                <button @click="confirmDelete(<?= (int)$post['id'] ?>)" class="text-red-400 hover:text-red-300 text-sm">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div x-show="lightboxOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed inset-0 z-50 flex items-center justify-center bg-black/95 p-4"
             @click="lightboxOpen = false" @keydown.escape.window="lightboxOpen = false">
            <div class="relative w-full h-full flex flex-col items-center justify-center" @click.stop>
                <div class="absolute top-4 right-4 flex space-x-2 z-10">
                    <a :href="lightboxImage" download="media.jpg" class="w-10 h-10 bg-slate-800 hover:bg-slate-700 rounded-full flex items-center justify-center text-white" title="Download">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                    </a>
                    <button @click="lightboxOpen = false" class="w-10 h-10 bg-slate-800 hover:bg-slate-700 rounded-full flex items-center justify-center text-white">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="absolute top-4 left-4 flex space-x-2 z-10">
                    <button @click="zoomLevel = Math.max(0.25, zoomLevel - 0.25)" class="w-10 h-10 bg-slate-800 hover:bg-slate-700 rounded-full flex items-center justify-center text-white" title="Zoom Out">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                        </svg>
                    </button>
                    <span class="w-16 h-10 bg-slate-800 rounded-full flex items-center justify-center text-white text-sm" x-text="zoomLevel + 'x'"></span>
                    <button @click="zoomLevel = Math.min(4, zoomLevel + 0.25)" class="w-10 h-10 bg-slate-800 hover:bg-slate-700 rounded-full flex items-center justify-center text-white" title="Zoom In">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                    </button>
                    <button @click="zoomLevel = 1" class="h-10 px-3 bg-slate-800 hover:bg-slate-700 rounded-full flex items-center justify-center text-white text-sm" title="Reset">
                        Reset
                    </button>
                </div>
                <img :src="lightboxImage"
                     :style="{ transform: 'translate(' + dragOffset.x + 'px, ' + dragOffset.y + 'px) scale(' + zoomLevel + ')', cursor: zoomLevel > 1 ? 'move' : 'zoom-in' }"
                     @mousedown="startDrag"
                     :class="{'select-none': zoomLevel > 1}"
                     class="max-w-none transition-transform duration-100"
                     :alt="lightboxAlt"
                     @click.self="zoomLevel = zoomLevel > 1 ? 1 : Math.min(4, zoomLevel + 0.5)">
                <p x-show="lightboxAlt" class="absolute bottom-4 left-1/2 -translate-x-1/2 text-center text-slate-300 mt-4 text-sm max-w-lg px-4" x-text="lightboxAlt"></p>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="mt-6 flex items-center justify-center space-x-2">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm">Previous</a>
            <?php endif; ?>

            <?php if ($paginationStart > 1): ?>
            <a href="?page=1" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm">1</a>
            <?php if ($paginationStart > 2): ?>
            <span class="px-2 text-slate-500">...</span>
            <?php endif; ?>
            <?php endif; ?>

            <?php foreach ($paginationRange as $p): ?>
            <?php if ($p === $page): ?>
            <span class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm"><?= $p ?></span>
            <?php else: ?>
            <a href="?page=<?= $p ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm"><?= $p ?></a>
            <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($paginationEnd < $totalPages): ?>
            <?php if ($paginationEnd < $totalPages - 1): ?>
            <span class="px-2 text-slate-500">...</span>
            <?php endif; ?>
            <a href="?page=<?= $totalPages ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 rounded-lg text-sm">Next</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </main>

    <script>
        function historyPage() {
            return {
                viewMode: 'compact',
                lightboxOpen: false,
                lightboxImage: '',
                lightboxAlt: '',
                zoomLevel: 1,
                isDragging: false,
                dragStart: { x: 0, y: 0 },
                dragOffset: { x: 0, y: 0 },
                loadedMedia: {},

                confirmDelete(id) {
                    if (!confirm('Delete this post? This cannot be undone.')) return;
                    this.deletePost(id);
                },

                async deletePost(id) {
                    try {
                        const response = await fetch('api/delete_post.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ id: id, type: 'master' })
                        });
                        const data = await response.json();

                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.error || 'Delete failed');
                        }
                    } catch (err) {
                        alert('Delete failed: ' + err.message);
                    }
                },

                async resyncPost(fetchedPostsId) {
                    if (!confirm('Sync this post to Bluesky?')) return;
                    const btn = event.target.closest('button');
                    btn.disabled = true;
                    btn.textContent = 'Syncing...';

                    try {
                        const response = await fetch('api/sync.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ post_ids: [fetchedPostsId] })
                        });
                        const data = await response.json();

                        if (data.success || data.synced > 0) {
                            location.reload();
                        } else {
                            alert(data.error || 'Sync failed');
                            btn.disabled = false;
                            btn.textContent = 'Sync to BSKY';
                        }
                    } catch (err) {
                        alert('Sync failed: ' + err.message);
                        btn.disabled = false;
                        btn.textContent = 'Sync to BSKY';
                    }
                },

                async loadTimelineMedia(postIndex, mediaIndex, url) {
                    const key = postIndex + '_' + mediaIndex;
                    if (this.loadedMedia[key]) return;

                    const btn = event.target;
                    btn.disabled = true;
                    btn.textContent = 'Loading...';

                    try {
                        const response = await fetch('api/load_media.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ url: url })
                        });
                        const data = await response.json();

                        if (data.success && data.data) {
                            this.loadedMedia[key] = 'data:' + data.mimeType + ';base64,' + data.data;
                            const container = btn.closest('.aspect-video');
                            const img = container.querySelector('.loaded-img');
                            img.src = this.loadedMedia[key];
                            img.classList.remove('loaded-img');
                            container.querySelector('[x-show="!loaded"]').style.display = 'none';
                            img.style.display = 'block';
                        }
                    } catch (err) {
                        console.error('Failed to load media:', err);
                    }

                    btn.disabled = false;
                    btn.textContent = 'Load';
                },

                openTimelineLightbox(postIndex, mediaIndex) {
                    const key = postIndex + '_' + mediaIndex;
                    this.lightboxImage = this.loadedMedia[key] || '';
                    this.lightboxAlt = '';
                    this.zoomLevel = 1;
                    this.dragOffset = { x: 0, y: 0 };
                    this.lightboxOpen = true;
                },

                startDrag(e) {
                    if (this.zoomLevel <= 1) return;
                    this.isDragging = true;
                    this.dragStart = { x: e.clientX - this.dragOffset.x, y: e.clientY - this.dragOffset.y };
                    document.addEventListener('mousemove', this.onDrag.bind(this));
                    document.addEventListener('mouseup', this.stopDrag.bind(this));
                },

                onDrag(e) {
                    if (!this.isDragging) return;
                    this.dragOffset = {
                        x: e.clientX - this.dragStart.x,
                        y: e.clientY - this.dragStart.y
                    };
                },

                stopDrag() {
                    this.isDragging = false;
                    document.removeEventListener('mousemove', this.onDrag.bind(this));
                    document.removeEventListener('mouseup', this.stopDrag.bind(this));
                }
            }
        }
    </script>
</body>
</html>