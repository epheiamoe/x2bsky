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
    Settings::initDefaults();
    $threadMediaPosition = Settings::get('thread_media_position', 'last');

    if (!$bsky->authenticate()) {
        throw new \Exception('Bluesky authentication failed - please check your credentials in .env');
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
        $quotedUrl = $post['quoted_url'] ?? null;

        if ($isQuote && $quotedUrl) {
            $text .= "\n\n引用 " . $quotedUrl;
        }

        if ($isRetweet && $post['original_author']) {
            $text = 'RT @' . $post['original_author'] . ': ' . $text;
            if ($quotedUrl) {
                $text .= "\n\n原推文 " . $quotedUrl;
            }
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
        $segmentErrors = [];
        $totalSegments = count($segments);

        foreach ($segments as $i => $segment) {
            $embed = null;

            $shouldAttachMedia = !empty($blobs) && (
                ($threadMediaPosition === 'first' && $i === 0) ||
                ($threadMediaPosition === 'last' && $i === $totalSegments - 1)
            );

            if ($shouldAttachMedia) {
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

            $progressNote = $totalSegments > 1 ? sprintf(' [%d/%d]', $i + 1, $totalSegments) : '';
            $segmentText = $segment['text'] . $progressNote;

            $result = $bsky->createPost($segmentText, $embed, $replyRef ? json_encode($replyRef) : null);

            if ($result && isset($result['uri'])) {
                $uri = $result['uri'];
                if ($i === 0) {
                    $rootUri = $uri;
                }
                $parentUri = $uri;
                $postUris[] = $uri;
            } else {
                $segmentErrors[] = sprintf('Segment %d/%d failed: %s', $i + 1, $totalSegments, $bsky->getLastError() ?? 'Unknown error');
            }
        }

        if (!empty($postUris)) {
            $finalUri = end($postUris);

            $stmt = $pdo->prepare('
                UPDATE fetched_posts
                SET synced = 1, synced_at = NOW(), synced_bsky_uri = ?
                WHERE id = ?
            ');
            $stmt->execute([$finalUri, $postId]);

            $stmt = $pdo->prepare('SELECT id FROM posts WHERE x_post_id = ?');
            $stmt->execute([$post['x_post_id']]);
            $postRow = $stmt->fetch();
            $masterPostId = $postRow ? $postRow['id'] : null;

            if ($masterPostId) {
                $stmt = $pdo->prepare('
                    INSERT INTO synced_destinations (post_id, platform, platform_post_url, platform_post_uri, status, synced_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ');
                $bskyHandle = Config::get('BSKY_HANDLE', 'your_handle');
                $platformUrl = 'https://bsky.app/profile/' . $bskyHandle . '/post/' . basename($finalUri);
                $stmt->execute([$masterPostId, 'bluesky', $platformUrl, $finalUri, 'synced']);

                foreach ($blobs as $blob) {
                    $stmt = $pdo->prepare('
                        INSERT INTO post_media (post_id, platform, media_type, original_url, local_path, alt_text)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ');
                    $stmt->execute([
                        $masterPostId,
                        'bluesky',
                        'image',
                        $platformUrl,
                        null,
                        $altTexts[$j] ?? ''
                    ]);
                }
            }

            $synced++;
            $resultData = ['id' => $postId, 'status' => 'success', 'uri' => $finalUri, 'segments' => $totalSegments, 'thread_position' => $threadMediaPosition];
            if (!empty($segmentErrors)) {
                $resultData['segment_errors'] = $segmentErrors;
            }
            $results[] = $resultData;

            Logger::info('Synced to Bluesky', ['id' => $postId, 'uri' => $finalUri, 'segments' => $totalSegments]);
        } else {
            $failed++;
            $errorMsg = !empty($segmentErrors) ? implode('; ', $segmentErrors) : 'Failed to create thread';
            $results[] = ['id' => $postId, 'status' => 'failed', 'error' => $errorMsg];
            Logger::error('Failed to sync', ['id' => $postId, 'errors' => $segmentErrors]);
        }
    }

    echo json_encode([
        'success' => $failed === 0,
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
