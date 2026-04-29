<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;

Config::init(__DIR__ . '/.env');

$error = '';
$remember = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (!Auth::verifyCsrf()) {
        $error = 'Invalid form submission — please refresh the page and try again.';
    } elseif (Auth::login($password)) {
        if ($remember) {
            Auth::remember($password);
        }
        header('Location: index.php');
        exit;
    } else {
        if (Auth::isPasswordFileBroken()) {
            $error = 'Password file is not readable by the web server. Run ./set_auth.sh on the server to fix.';
        } else {
            $error = 'Invalid password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - x2bsky</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-slate-900 to-slate-800 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md px-6">
        <div class="bg-slate-800/50 backdrop-blur rounded-2xl p-8 shadow-2xl border border-slate-700">
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-white mb-2">x2bsky</h1>
                <p class="text-slate-400">Sign in to continue</p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/50 text-red-400 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <?= Auth::csrfField() ?>
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-2">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autofocus
                        class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="Enter your password"
                    >
                </div>

                <div class="flex items-center">
                    <input
                        type="checkbox"
                        id="remember"
                        name="remember"
                        class="w-4 h-4 rounded border-slate-600 bg-slate-900 text-blue-500 focus:ring-blue-500 focus:ring-offset-0"
                    >
                    <label for="remember" class="ml-2 text-sm text-slate-400">Remember me</label>
                </div>

                <button
                    type="submit"
                    class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-500 text-white font-medium rounded-lg transition duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-slate-800"
                >
                    Sign In
                </button>
            </form>
        </div>
    </div>
</body>
</html>
