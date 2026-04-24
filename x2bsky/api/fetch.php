<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Auth;
use X2BSky\Database;
use X2BSky\Settings;
use X2BSky\Api\XApiClient;
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
$count = max(5, min(100, (int)($input['count'] ?? Settings::get('fetch_default_count', 20))));

try {
    $xClient = new XApiClient();
    $tweets = $xClient->fetchUserTweets($count);

    if (empty($tweets)) {
        echo json_encode([
            'success' => true,
            'fetched' => 0,
            'posts' => [],
            'message' => 'No new tweets to fetch'
        ]);
        exit;
    }

    $pdo = Database::getInstance();
    $inserted = 0;
    $skipped = 0;
    $posts = [];

    foreach ($tweets as $tweet) {
        $xPostId = $tweet['id'];
        $postType = $tweet['_post_type'] ?? 'original';

        $stmt = $pdo->prepare('SELECT id FROM fetched_posts WHERE x_post_id = ?');
        $stmt->execute([$xPostId]);
        if ($stmt->fetch()) {
            $skipped++;
            continue;
        }

        $text = $tweet['text'] ?? '';
        $textHash = md5($text);
        $xUrl = 'https://x.com/i/status/' . $xPostId;
        $xCreatedAt = $tweet['created_at'] ?? null;

        $isRetweet = ($postType === 'retweeted');
        $isQuote = ($postType === 'quoted');

        $originalAuthor = null;
        if ($isRetweet && isset($tweet['text'])) {
            if (preg_match('/^RT @(\w+):/', $tweet['text'], $matches)) {
                $originalAuthor = $matches[1];
            }
        }

        $mediaJson = null;
        if (!empty($tweet['_media'])) {
            $mediaData = [];
            foreach ($tweet['_media'] as $media) {
                $mediaData[] = [
                    'media_key' => $media['media_key'] ?? '',
                    'type' => $media['type'] ?? 'unknown',
                    'url' => $media['url'] ?? $media['preview_image_url'] ?? '',
                    'alt_text' => $media['alt_text'] ?? '',
                    'width' => $media['width'] ?? 0,
                    'height' => $media['height'] ?? 0,
                ];
            }
            $mediaJson = json_encode($mediaData);
        }

        $stmt = $pdo->prepare('
            INSERT INTO fetched_posts (x_post_id, x_post_url, text, text_hash, is_retweet, is_quote, original_author, media_json, x_created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$xPostId, $xUrl, $text, $textHash, $isRetweet, $isQuote, $originalAuthor, $mediaJson, $xCreatedAt]);

        $inserted++;
        $posts[] = [
            'id' => $pdo->lastInsertId(),
            'x_post_id' => $xPostId,
            'x_post_url' => $xUrl,
            'text' => mb_substr($text, 0, 150),
            'is_retweet' => $isRetweet,
            'is_quote' => $isQuote,
            'original_author' => $originalAuthor,
            'has_media' => !empty($tweet['_media']),
            'x_created_at' => $xCreatedAt,
        ];

        Logger::info('Fetched tweet', ['x_post_id' => $xPostId, 'type' => $postType]);
    }

    echo json_encode([
        'success' => true,
        'fetched' => $inserted,
        'skipped' => $skipped,
        'posts' => $posts
    ]);

} catch (\Throwable $e) {
    Logger::error('Fetch failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
