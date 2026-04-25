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

    $syncIncludeRts = (bool)Settings::get('sync_include_rts', false);
    $exclude = ['replies'];
    if (!$syncIncludeRts) {
        $exclude[] = 'retweets';
    }

    $tweets = $xClient->fetchUserTweets($count, null, $exclude);

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
        $isRetweet = ($postType === 'retweeted');
        $isQuote = ($postType === 'quoted');

        $originalAuthor = null;
        $originalUrl = null;

        if ($isRetweet) {
            if (preg_match('/^RT @(\w+):\s*/', $text, $matches)) {
                $originalAuthor = $matches[1];
                $text = preg_replace('/^RT @(\w+):\s*/', '', $text, 1);
            }
            if (!empty($tweet['entities']['urls'])) {
                foreach ($tweet['entities']['urls'] as $url) {
                    if (!empty($url['expanded_url']) && !empty($url['url'])) {
                        $text = str_replace($url['url'], '', $text);
                        if (!$originalUrl) {
                            $expanded = $url['expanded_url'];
                            if (preg_match('#(https?://x\.com/\w+/status/\d+)#', $expanded, $m)) {
                                $originalUrl = $m[1];
                            } else {
                                $originalUrl = $expanded;
                            }
                        }
                    }
                }
            }
            $text = trim($text);
        } elseif ($isQuote && !empty($tweet['entities']['urls'])) {
            foreach ($tweet['entities']['urls'] as $url) {
                if (!empty($url['url'])) {
                    $text = str_replace($url['url'], '', $text);
                }
            }
            $text = trim($text);
        }

        $textHash = md5($text);
        $xUrl = 'https://x.com/i/status/' . $xPostId;
        $xCreatedAt = $tweet['created_at'] ?? null;

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
            INSERT INTO fetched_posts (x_post_id, x_post_url, text, text_hash, is_retweet, is_quote, original_author, quoted_url, media_json, x_created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $urlToStore = $isRetweet ? ($originalUrl ?? null) : ($tweet['_quoted_url'] ?? null);
        $stmt->execute([$xPostId, $xUrl, $text, $textHash, $isRetweet, $isQuote, $originalAuthor, $urlToStore, $mediaJson, $xCreatedAt]);

        $stmt = $pdo->prepare('
            INSERT INTO posts (x_post_id, x_post_url, x_author, text, text_hash, is_retweet, is_quote, original_author, x_created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$xPostId, $xUrl, null, $text, $textHash, $isRetweet, $isQuote, $originalAuthor, $xCreatedAt]);

        if (!empty($tweet['_media'])) {
            $postId = $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT id FROM posts WHERE x_post_id = ?');
            $stmt->execute([$xPostId]);
            $postRow = $stmt->fetch();
            $postId = $postRow ? $postRow['id'] : null;

            if ($postId) {
                foreach ($tweet['_media'] as $media) {
                    $mediaUrl = $media['url'] ?? $media['preview_image_url'] ?? '';
                    if ($mediaUrl) {
                        $stmt = $pdo->prepare('
                            INSERT INTO post_media (post_id, platform, media_type, original_url, alt_text, width, height)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ');
                        $stmt->execute([
                            $postId,
                            'x',
                            $media['type'] ?? 'image',
                            $mediaUrl,
                            $media['alt_text'] ?? '',
                            $media['width'] ?? 0,
                            $media['height'] ?? 0
                        ]);
                    }
                }
            }
        }

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
            'quoted_url' => $tweet['_quoted_url'] ?? null,
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
