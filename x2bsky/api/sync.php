<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;
use X2BSky\Settings;
use X2BSky\Api\BlueskyClient;
use X2BSky\Api\TextProcessor;
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
$postIds = $input['post_ids'] ?? [];

if (empty($postIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No posts selected']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $bsky = new BlueskyClient();

    if (!$bsky->authenticate()) {
        throw new \Exception('Bluesky authentication failed');
    }

    $synced = 0;
    $failed = 0;
    $results = [];

    foreach ($postIds as $postId) {
        $stmt = $pdo->prepare('SELECT * FROM fetched_posts WHERE id = ? AND synced = 0');
        $stmt->execute([$postId]);
        $post = $stmt->fetch();

        if (!$post) {
            $failed++;
            $results[] = ['id' => $postId, 'status' => 'not_found'];
            continue;
        }

        $text = $post['text'];
        $isQuote = (bool)$post['is_quote'];
        $isRetweet = (bool)$post['is_retweet'];

        if ($isQuote) {
            $text .= ' ' . $post['x_post_url'];
        }

        if ($isRetweet && $post['original_author']) {
            $text = 'RT @' . $post['original_author'] . ': ' . $text;
        }

        $mediaJson = $post['media_json'];
        $mediaItems = $mediaJson ? json_decode($mediaJson, true) : [];

        $blobs = [];
        $altTexts = [];

        foreach ($mediaItems as $media) {
            if (empty($media['url'])) {
                continue;
            }

            $imgData = @file_get_contents($media['url']);
            if (!$imgData) {
                Logger::warning('Failed to download media', ['url' => $media['url']]);
                continue;
            }

            $tempFile = sys_get_temp_dir() . '/x2bsky_' . bin2hex(random_bytes(8)) . '.jpg';
            file_put_contents($tempFile, $imgData);

            $size = @getimagesize($tempFile);
            if ($size && ($size[0] > 4000 || $size[1] > 4000 || strlen($imgData) > 2097152)) {
                $tempFile = compressImage($tempFile, $size);
            }

            $mime = getMimeType($tempFile);
            $blob = $bsky->uploadBlob($tempFile);

            @unlink($tempFile);

            if ($blob) {
                $blobs[] = $blob;
                $altTexts[] = $media['alt_text'] ?? '';
            }
        }

        $segments = TextProcessor::splitForBluesky($text, 300);
        $segments = TextProcessor::addThreadNotation($segments);

        $parentUri = null;
        $rootUri = null;
        $postUris = [];

        foreach ($segments as $i => $segment) {
            $embed = null;
            if ($i === 0 && !empty($blobs)) {
                $images = [];
                foreach ($blobs as $j => $blob) {
                    $images[] = [
                        'image' => $blob,
                        'alt' => $altTexts[$j] ?? ''
                    ];
                }
                $embed = [
                    '$type' => 'app.bsky.embed.images',
                    'images' => $images
                ];
            }

            $replyRef = null;
            if ($parentUri) {
                $replyRef = ['parent' => $parentUri, 'root' => $rootUri];
            }

            $result = $bsky->createPost($segment['text'], $embed, $replyRef ? json_encode($replyRef) : null);

            if ($result && isset($result['uri'])) {
                $uri = $result['uri'];
                if ($i === 0) {
                    $rootUri = $uri;
                }
                $parentUri = $uri;
                $postUris[] = $uri;
            }
        }

        if (!empty($postUris)) {
            $finalUri = end($postUris);

            $stmt = $pdo->prepare('
                UPDATE fetched_posts
                SET synced = 1, synced_at = datetime("now"), synced_bsky_uri = ?
                WHERE id = ?
            ');
            $stmt->execute([$finalUri, $postId]);

            $synced++;
            $results[] = ['id' => $postId, 'status' => 'success', 'uri' => $finalUri];

            Logger::info('Synced to Bluesky', ['id' => $postId, 'uri' => $finalUri]);
        } else {
            $failed++;
            $results[] = ['id' => $postId, 'status' => 'failed'];
            Logger::error('Failed to sync', ['id' => $postId]);
        }
    }

    echo json_encode([
        'success' => true,
        'synced' => $synced,
        'failed' => $failed,
        'results' => $results
    ]);

} catch (\Throwable $e) {
    Logger::error('Sync failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function getMimeType(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];
    return $mimeTypes[$ext] ?? 'image/jpeg';
}

function compressImage(string $path, array $size): string
{
    $quality = 85;
    $maxDim = 4000;

    while (true) {
        $newWidth = min($size[0], $maxDim);
        $newHeight = min($size[1], $maxDim);

        if ($size[0] <= $maxDim && $size[1] <= $maxDim && filesize($path) <= 2097152) {
            break;
        }

        $scale = min($newWidth / $size[0], $newHeight / $size[1], 0.8);
        $newWidth = (int)($size[0] * $scale);
        $newHeight = (int)($size[1] * $scale);

        $src = null;
        switch ($size['mime'] ?? 'image/jpeg') {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($path);
                break;
            case 'image/webp':
                $src = @imagecreatefromwebp($path);
                break;
        }

        if (!$src) {
            return $path;
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $size[0], $size[1]);

        $newPath = $path . '.compressed.jpg';
        imagejpeg($dst, $newPath, $quality);
        imagedestroy($src);
        imagedestroy($dst);

        if (filesize($newPath) < filesize($path)) {
            @unlink($path);
            $path = $newPath;
            $size = @getimagesize($path);
        } else {
            @unlink($newPath);
            break;
        }

        $quality -= 10;
        if ($quality < 20) {
            break;
        }
    }

    return $path;
}
