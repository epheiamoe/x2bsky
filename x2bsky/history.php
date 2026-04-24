<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;

Config::init(__DIR__ . '/.env');
Auth::requireAuth();

$pdo = Database::getInstance();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->query('SELECT COUNT(*) FROM synced_posts');
$totalPosts = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('
    SELECT * FROM synced_posts
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
');
$stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
$stmt->execute();
$posts = $stmt->fetchAll();

$stmt = $pdo->query('
    SELECT status, COUNT(*) as count
    FROM synced_posts
    GROUP BY status
');
$statsByStatus = [];
while ($row = $stmt->fetch()) {
    $statsByStatus[$row['status']] = (int) $row['count'];
}

$totalPages = ceil($totalPosts / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History - x2bsky</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <nav class="border-b border-slate-700 bg-slate-800/50 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-8">
                    <h1 class="text-xl font-bold text-white">x2bsky</h1>
                    <div class="hidden md:flex space-x-6">
                        <a href="index.php" class="text-slate-400 hover:text-white transition">Dashboard</a>
                        <a href="history.php" class="text-white font-medium">History</a>
                        <a href="settings.php" class="text-slate-400 hover:text-white transition">Settings</a>
                    </div>
                </div>
                <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <div class="grid grid-cols-3 gap-4 mb-8">
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Synced</p>
                <p class="text-2xl font-bold text-green-400"><?= $statsByStatus['synced'] ?? 0 ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Pending</p>
                <p class="text-2xl font-bold text-yellow-400"><?= $statsByStatus['pending'] ?? 0 ?></p>
            </div>
            <div class="bg-slate-800/50 backdrop-blur rounded-xl p-4 border border-slate-700">
                <p class="text-slate-400 text-sm">Failed</p>
                <p class="text-2xl font-bold text-red-400"><?= $statsByStatus['failed'] ?? 0 ?></p>
            </div>
        </div>

        <div class="bg-slate-800/50 backdrop-blur rounded-xl border border-slate-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-700/50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">X Post</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Preview</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php foreach ($posts as $post): ?>
                        <tr class="hover:bg-slate-700/30 transition">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-400">
                                <?= date('Y-m-d H:i', strtotime($post['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <a href="<?= htmlspecialchars($post['x_post_url']) ?>" target="_blank" class="text-blue-400 hover:text-blue-300">
                                    <?= htmlspecialchars($post['x_post_id']) ?>
                                </a>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-300 max-w-xs truncate">
                                <?= htmlspecialchars($post['text_preview'] ?? '') ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 rounded text-xs font-medium <?= $post['status'] === 'synced' ? 'bg-green-500/20 text-green-400' : ($post['status'] === 'failed' ? 'bg-red-500/20 text-red-400' : 'bg-yellow-500/20 text-yellow-400') ?>">
                                    <?= htmlspecialchars($post['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($posts)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-slate-500">No posts synced yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-slate-700 flex items-center justify-between">
                <span class="text-sm text-slate-400">
                    Page <?= $page ?> of <?= $totalPages ?>
                </span>
                <div class="flex space-x-2">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="px-3 py-1 bg-slate-700 hover:bg-slate-600 rounded text-sm">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
