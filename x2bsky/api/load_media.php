<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Logger;

header('Content-Type: application/json');

Config::init(APP_ROOT . '/.env');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$url = $input['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No URL provided']);
    exit;
}

if (!preg_match('#^https?://pbs\.twimg\.com/#', $url)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid URL']);
    exit;
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

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => true,
            'max_redirects' => 3,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]
    ]);

    $data = @file_get_contents($url, false, $context);

    if ($data === false) {
        throw new \Exception('Failed to download media');
    }

    $tempFile = sys_get_temp_dir() . '/x2bsky_media_' . bin2hex(random_bytes(8));
    file_put_contents($tempFile, $data);

    $mimeType = getMimeType($url);
    $base64 = base64_encode(file_get_contents($tempFile));

    @unlink($tempFile);

    echo json_encode([
        'success' => true,
        'data' => $base64,
        'mimeType' => $mimeType
    ]);

} catch (\Throwable $e) {
    Logger::error('Failed to load media', ['url' => $url, 'error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}