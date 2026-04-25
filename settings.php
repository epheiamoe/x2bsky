<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Settings;

Config::init(__DIR__ . '/.env');
Auth::requireAuth();
Settings::initDefaults();

$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'sync';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!Auth::verifyPassword($currentPassword)) {
            $error = 'Current password is incorrect';
        } elseif (strlen($newPassword) < 8) {
            $error = 'New password must be at least 8 characters';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match';
        } elseif (Auth::setPassword($newPassword)) {
            $success = 'Password changed successfully';
        } else {
            $error = 'Failed to save new password';
        }
        $activeTab = 'password';
    }

    if (isset($_POST['action']) && $_POST['action'] === 'sync_settings') {
        $cronEnabled = isset($_POST['cron_enabled']);
        $cronInterval = max(1, min(60, (int)($_POST['cron_interval'] ?? 5)));
        $syncCount = max(5, min(100, (int)($_POST['sync_count'] ?? 10)));
        $syncIncludeRts = isset($_POST['sync_include_rts']);
        $syncIncludeQuotes = isset($_POST['sync_include_quotes']);
        $fetchDefaultCount = max(5, min(100, (int)($_POST['fetch_default_count'] ?? 20)));
        $threadMediaPosition = in_array($_POST['thread_media_position'] ?? 'last', ['first', 'last']) ? $_POST['thread_media_position'] : 'last';
        $historyPerPage = max(5, min(50, (int)($_POST['history_per_page'] ?? 20)));
        $historyMaxPages = max(1, min(100, (int)($_POST['history_max_pages'] ?? 10)));

        Settings::set('cron_enabled', $cronEnabled);
        Settings::set('cron_interval', $cronInterval);
        Settings::set('sync_count', $syncCount);
        Settings::set('sync_include_rts', $syncIncludeRts);
        Settings::set('sync_include_quotes', $syncIncludeQuotes);
        Settings::set('fetch_default_count', $fetchDefaultCount);
        Settings::set('thread_media_position', $threadMediaPosition);
        Settings::set('history_per_page', $historyPerPage);
        Settings::set('history_max_pages', $historyMaxPages);

        $success = 'Sync settings saved successfully';
        $activeTab = 'sync';
    }
}

$cronEnabled = Settings::get('cron_enabled', false);
$cronInterval = Settings::get('cron_interval', 5);
$syncCount = Settings::get('sync_count', 10);
$syncIncludeRts = Settings::get('sync_include_rts', false);
$syncIncludeQuotes = Settings::get('sync_include_quotes', true);
$fetchDefaultCount = Settings::get('fetch_default_count', 20);
$threadMediaPosition = Settings::get('thread_media_position', 'last');
$historyPerPage = Settings::get('history_per_page', 20);
$historyMaxPages = Settings::get('history_max_pages', 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - x2bsky</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen">
    <nav class="border-b border-slate-700 bg-slate-800/50 backdrop-blur sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-8">
                    <a href="index.php" class="text-xl font-bold text-white">x2bsky</a>
                    <div class="flex space-x-6">
                        <a href="index.php" class="text-slate-400 hover:text-white transition">Dashboard</a>
                        <a href="archive.php" class="text-slate-400 hover:text-white transition">Archive</a>
                        <a href="settings.php" class="text-white font-medium">Settings</a>
                    </div>
                </div>
                <a href="logout.php" class="text-sm text-slate-400 hover:text-white transition">Logout</a>
            </div>
        </div>
    </nav>

    <main class="max-w-2xl mx-auto px-6 py-8">
        <h1 class="text-2xl font-bold text-white mb-8">Settings</h1>

        <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-500/50 text-red-400 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="bg-green-500/10 border border-green-500/50 text-green-400 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <div class="flex space-x-4 mb-8">
            <a href="?tab=sync" class="px-4 py-2 rounded-lg <?= $activeTab === 'sync' ? 'bg-blue-600 text-white' : 'bg-slate-800 text-slate-400 hover:bg-slate-700' ?>">
                Sync Settings
            </a>
            <a href="?tab=password" class="px-4 py-2 rounded-lg <?= $activeTab === 'password' ? 'bg-blue-600 text-white' : 'bg-slate-800 text-slate-400 hover:bg-slate-700' ?>">
                Password
            </a>
        </div>

        <?php if ($activeTab === 'sync'): ?>
        <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
            <h2 class="text-lg font-semibold text-white mb-6">Sync Settings</h2>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="sync_settings">

                <div class="flex items-center">
                    <input
                        type="checkbox"
                        id="cron_enabled"
                        name="cron_enabled"
                        value="1"
                        <?= $cronEnabled ? 'checked' : '' ?>
                        class="w-5 h-5 rounded border-slate-600 bg-slate-900 text-blue-500 focus:ring-blue-500 focus:ring-offset-0"
                    >
                    <label for="cron_enabled" class="ml-3 text-slate-300">Enable automatic sync</label>
                </div>

                <div>
                    <label for="cron_interval" class="block text-sm font-medium text-slate-300 mb-2">Sync Interval (minutes)</label>
                    <input
                        type="number"
                        id="cron_interval"
                        name="cron_interval"
                        value="<?= (int)$cronInterval ?>"
                        min="1"
                        max="60"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">How often to run automatic sync (1-60 minutes)</p>
                </div>

                <div>
                    <label for="sync_count" class="block text-sm font-medium text-slate-300 mb-2">Posts per Sync</label>
                    <input
                        type="number"
                        id="sync_count"
                        name="sync_count"
                        value="<?= (int)$syncCount ?>"
                        min="5"
                        max="100"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Number of recent posts to fetch each sync (5-100)</p>
                </div>

                <div class="flex items-center">
                    <input
                        type="checkbox"
                        id="sync_include_rts"
                        name="sync_include_rts"
                        value="1"
                        <?= $syncIncludeRts ? 'checked' : '' ?>
                        class="w-5 h-5 rounded border-slate-600 bg-slate-900 text-blue-500 focus:ring-blue-500 focus:ring-offset-0"
                    >
                    <label for="sync_include_rts" class="ml-3 text-slate-300">Include Retweets (RT)</label>
                </div>

                <div class="flex items-center">
                    <input
                        type="checkbox"
                        id="sync_include_quotes"
                        name="sync_include_quotes"
                        value="1"
                        <?= $syncIncludeQuotes ? 'checked' : '' ?>
                        class="w-5 h-5 rounded border-slate-600 bg-slate-900 text-blue-500 focus:ring-blue-500 focus:ring-offset-0"
                    >
                    <label for="sync_include_quotes" class="ml-3 text-slate-300">Include Quotes (with link)</label>
                </div>

                <div>
                    <label for="thread_media_position" class="block text-sm font-medium text-slate-300 mb-2">Thread Media Position</label>
                    <select
                        id="thread_media_position"
                        name="thread_media_position"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="last" <?= $threadMediaPosition === 'last' ? 'selected' : '' ?>>Last post in thread</option>
                        <option value="first" <?= $threadMediaPosition === 'first' ? 'selected' : '' ?>>First post in thread</option>
                    </select>
                    <p class="text-xs text-slate-500 mt-1">Where to attach images in multi-post threads</p>
                </div>

                <div>
                    <label for="fetch_default_count" class="block text-sm font-medium text-slate-300 mb-2">Fetch Count</label>
                    <input
                        type="number"
                        id="fetch_default_count"
                        name="fetch_default_count"
                        value="<?= (int)$fetchDefaultCount ?>"
                        min="5"
                        max="100"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Posts to fetch per fetch operation (5-100, cost ~$0.002 per post)</p>
                </div>

                <div>
                    <label for="history_per_page" class="block text-sm font-medium text-slate-300 mb-2">History Per Page</label>
                    <input
                        type="number"
                        id="history_per_page"
                        name="history_per_page"
                        value="<?= (int)$historyPerPage ?>"
                        min="5"
                        max="50"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Posts per page in History/Archive (5-50)</p>
                </div>

                <div>
                    <label for="history_max_pages" class="block text-sm font-medium text-slate-300 mb-2">Max Pagination Pages</label>
                    <input
                        type="number"
                        id="history_max_pages"
                        name="history_max_pages"
                        value="<?= (int)$historyMaxPages ?>"
                        min="1"
                        max="100"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Maximum pages to show in pagination (1-100)</p>
                </div>

                <button
                    type="submit"
                    class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-500 text-white font-medium rounded-lg transition duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-slate-800"
                >
                    Save Sync Settings
                </button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'password'): ?>
        <div class="bg-slate-800/50 backdrop-blur rounded-xl p-6 border border-slate-700">
            <h2 class="text-lg font-semibold text-white mb-6">Change Password</h2>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="change_password">

                <div>
                    <label for="current_password" class="block text-sm font-medium text-slate-300 mb-2">Current Password</label>
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        required
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div>
                    <label for="new_password" class="block text-sm font-medium text-slate-300 mb-2">New Password</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        required
                        minlength="8"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="text-xs text-slate-500 mt-1">Minimum 8 characters</p>
                </div>

                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-slate-300 mb-2">Confirm New Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        required
                        minlength="8"
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-500 text-white font-medium rounded-lg transition duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-slate-800"
                >
                    Change Password
                </button>
            </form>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
