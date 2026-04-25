<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;

Config::init(__DIR__ . '/.env');
Auth::requireAuth();

$pdo = Database::getInstance();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('
    SELECT fp.*,
           p.id as master_post_id
    FROM fetched_posts fp
    LEFT JOIN posts p ON p.x_post_id = fp.x_post_id
    WHERE fp.id = ?
    UNION
    SELECT fp.*,
           p.id as master_post_id
    FROM posts p
    LEFT JOIN fetched_posts fp ON fp.x_post_id = p.x_post_id
    WHERE p.id = ?
');
$stmt->execute([$id, $id]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: fetch.php');
    exit;
}

$mediaItems = $post['media_json'] ? json_decode($post['media_json'], true) : [];

$stmt = $pdo->prepare('
    SELECT * FROM post_media WHERE post_id = ? AND platform = ?
');
$stmt->execute([$post['master_post_id'], 'x']);
$postMedia = $stmt->fetchAll();

$stmt = $pdo->prepare('
    SELECT * FROM synced_destinations WHERE post_id = ? ORDER BY platform
');
$stmt->execute([$post['master_post_id']]);
$destinations = $stmt->fetchAll();

$destinationsByPlatform = [];
foreach ($destinations as $dest) {
    $destinationsByPlatform[$dest['platform']] = $dest;
}

function formatDate($datetime) {
    if (!$datetime) return 'N/A';
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';

    return date('M j, Y', $timestamp);
}

function getMimeType(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
    ];
    return $mimeTypes[$ext] ?? 'application/octet-stream';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Details - x2bsky</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <nav class="border-b border-slate-700 bg-slate-800/50 backdrop-blur sticky top-0 z-50">
        <div class="max-w-3xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <a href="<?= ($_GET['from'] ?? '') === 'archive' ? 'archive.php' : 'fetch.php' ?>" class="text-slate-400 hover:text-white transition flex items-center">
                        <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                        Back
                    </a>
                    <h1 class="text-xl font-bold text-white">Post Details</h1>
                </div>
                <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-3xl mx-auto px-6 py-8" x-data="postDetail()">
        <div class="bg-slate-800/50 backdrop-blur rounded-xl border border-slate-700 overflow-hidden">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                            <?= strtoupper(substr($post['original_author'] ?? 'X', 0, 1)) ?>
                        </div>
                        <div>
                            <p class="font-semibold text-white">@<?= htmlspecialchars($post['original_author'] ?? 'unknown') ?></p>
                            <p class="text-sm text-slate-400"><?= formatDate($post['x_created_at']) ?></p>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($post['is_retweet']): ?>
                        <span class="px-2 py-1 rounded text-xs font-medium bg-purple-500/20 text-purple-400">RT</span>
                        <?php endif; ?>
                        <?php if ($post['is_quote']): ?>
                        <span class="px-2 py-1 rounded text-xs font-medium bg-blue-500/20 text-blue-400">Quote</span>
                        <?php endif; ?>
                        <?php if (!$post['is_retweet'] && !$post['is_quote']): ?>
                        <span class="px-2 py-1 rounded text-xs font-medium bg-green-500/20 text-green-400">Original</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mb-6">
                    <p class="text-lg text-slate-100 whitespace-pre-wrap break-all"><?= htmlspecialchars($post['text']) ?></p>
                </div>

                <div class="flex items-center space-x-4 text-sm text-slate-400 mb-6 pb-6 border-b border-slate-700">
                    <a href="<?= htmlspecialchars($post['x_post_url']) ?>" target="_blank" class="hover:text-blue-400 flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                        </svg>
                        View on X
                    </a>
                    <?php if (!empty($destinationsByPlatform['bluesky'])): ?>
                    <a href="<?= htmlspecialchars($destinationsByPlatform['bluesky']['platform_post_url']) ?>" target="_blank" class="hover:text-indigo-400 flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                        View on Bluesky
                    </a>
                    <?php else: ?>
                    <span class="text-slate-500 flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                        Not synced yet
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($mediaItems)): ?>
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-medium text-slate-300">Media (<?= count($mediaItems) ?>)</h3>
                        <button @click="loadAllMedia"
                                :disabled="loading"
                                class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 disabled:bg-slate-600 text-white rounded-lg text-sm transition flex items-center">
                            <svg x-show="loading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span x-show="!loading">Load All Media</span>
                            <span x-show="loading">Loading...</span>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3" x-show="!showMedia">
                        <?php foreach ($mediaItems as $index => $media): ?>
                        <div class="relative bg-slate-700/50 rounded-lg overflow-hidden aspect-video flex items-center justify-center">
                            <div class="text-center p-4">
                                <svg class="w-10 h-10 mx-auto text-slate-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-sm text-slate-400 mb-2"><?= htmlspecialchars($media['type'] ?? 'image') ?></p>
                                <?php if (!empty($media['alt_text'])): ?>
                                <p class="text-xs text-slate-500 mb-2 truncate max-w-full"><?= htmlspecialchars($media['alt_text']) ?></p>
                                <?php endif; ?>
                                <button @click="loadMedia(<?= $index ?>)"
                                        :disabled="loadingIndex === <?= $index ?>"
                                        class="px-3 py-1 bg-blue-600 hover:bg-blue-500 disabled:bg-slate-600 text-white rounded text-xs transition">
                                    <span x-show="loadingIndex !== <?= $index ?>">Load</span>
                                    <span x-show="loadingIndex === <?= $index ?>">Loading...</span>
                                </button>
                            </div>
                            <img x-show="loadedImages[<?= $index ?>]"
                                 @click="openLightbox(<?= $index ?>)"
                                 :src="loadedImages[<?= $index ?>]"
                                 class="absolute inset-0 w-full h-full object-cover cursor-pointer hover:opacity-90"
                                 alt="<?= htmlspecialchars($media['alt_text'] ?? '') ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div x-show="showMedia" class="grid grid-cols-2 gap-3">
                        <?php foreach ($mediaItems as $index => $media): ?>
                        <div class="relative rounded-lg overflow-hidden">
                            <img :src="loadedImages[<?= $index ?>]"
                                 @click="openLightbox(<?= $index ?>)"
                                 class="w-full object-contain max-h-96 cursor-pointer hover:opacity-90"
                                 alt="<?= htmlspecialchars($media['alt_text'] ?? '') ?>">
                            <?php if (!empty($media['alt_text'])): ?>
                            <p class="text-xs text-slate-500 mt-1 px-1"><?= htmlspecialchars($media['alt_text']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <p x-show="error" class="mt-3 text-red-400 text-sm" x-text="error"></p>
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
                <?php else: ?>
                <div class="text-center py-8 text-slate-500">
                    <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p>No media in this post</p>
                </div>
                <?php endif; ?>

                <div class="mt-6 pt-6 border-t border-slate-700 text-sm text-slate-500">
                    <p>Post ID: <?= htmlspecialchars($post['x_post_id']) ?></p>
                    <p>Fetched: <?= formatDate($post['fetched_at']) ?></p>
                    <?php if ($post['synced']): ?>
                    <p class="text-green-400">Synced: <?= formatDate($post['synced_at']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        function postDetail() {
            return {
                loading: false,
                loadingIndex: null,
                loadedImages: [],
                showMedia: false,
                error: '',
                lightboxOpen: false,
                lightboxImage: '',
                lightboxAlt: '',
                zoomLevel: 1,
                isDragging: false,
                dragStart: { x: 0, y: 0 },
                dragOffset: { x: 0, y: 0 },

                mediaUrls: <?= json_encode(array_column($mediaItems, 'url')) ?>,
                mediaAlts: <?= json_encode(array_column($mediaItems, 'alt_text')) ?>,

                async loadMedia(index) {
                    if (this.loadingIndex !== null) return;

                    this.loadingIndex = index;
                    this.error = '';

                    try {
                        const response = await fetch('api/load_media.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({ url: this.mediaUrls[index] })
                        });

                        const contentType = response.headers.get('content-type') || '';
                        let data;
                        if (contentType.includes('application/json')) {
                            data = await response.json();
                        } else {
                            const text = await response.text();
                            this.error = 'Server error: ' + text.substring(0, 100);
                            this.loadingIndex = null;
                            return;
                        }

                        if (data.success && data.data) {
                            this.loadedImages[index] = 'data:' + data.mimeType + ';base64,' + data.data;
                        } else {
                            this.error = data.error || 'Failed to load media';
                        }
                    } catch (err) {
                        this.error = 'Request failed: ' + err.message;
                    }

                    this.loadingIndex = null;
                },

                async loadAllMedia() {
                    this.loading = true;
                    this.error = '';
                    this.loadedImages = [];

                    try {
                        for (let i = 0; i < this.mediaUrls.length; i++) {
                            const response = await fetch('api/load_media.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/json'},
                                body: JSON.stringify({ url: this.mediaUrls[i] })
                            });

                            const contentType = response.headers.get('content-type') || '';
                            if (contentType.includes('application/json')) {
                                const data = await response.json();
                                if (data.success && data.data) {
                                    this.loadedImages[i] = 'data:' + data.mimeType + ';base64,' + data.data;
                                }
                            }
                        }

                        if (this.loadedImages.length > 0) {
                            this.showMedia = true;
                        } else {
                            this.error = 'Failed to load any media';
                        }
                    } catch (err) {
                        this.error = 'Request failed: ' + err.message;
                    }

                    this.loading = false;
                },

                openLightbox(index) {
                    this.lightboxImage = this.loadedImages[index];
                    this.lightboxAlt = this.mediaAlts[index] || '';
                    this.zoomLevel = 1;
                    this.dragOffset = { x: 0, y: 0 };
                    this.lightboxOpen = true;
                },

                startDrag(e) {
                    if (this.zoomLevel <= 1) return;
                    this.isDragging = true;
                    this.dragStart = { x: e.clientX - this.dragOffset.x, y: e.clientY - this.dragOffset.y };
                    document.addEventListener('mousemove', this.onDrag);
                    document.addEventListener('mouseup', this.stopDrag);
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
                    document.removeEventListener('mousemove', this.onDrag);
                    document.removeEventListener('mouseup', this.stopDrag);
                }
            }
        }
    </script>
</body>
</html>