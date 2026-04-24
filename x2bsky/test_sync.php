<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Database;
use X2BSky\Api\BlueskyClient;
use X2BSky\Api\TextProcessor;

Config::init(__DIR__ . '/.env');

$pdo = Database::getInstance();
$stmt = $pdo->query('SELECT * FROM queue WHERE status = "processing" LIMIT 1');
$item = $stmt->fetch();

if (!$item) {
    echo "No processing items\n";
    exit;
}

echo "Processing: " . $item['x_post_id'] . "\n";
$postData = json_decode($item['post_data'], true);

$text = $postData['text'] ?? '';
$media = $postData['_media'] ?? [];

echo "Text length: " . mb_strlen($text) . "\n";
echo "Media count: " . count($media) . "\n";

$bsky = new BlueskyClient();
echo "Authenticating...\n";
if (!$bsky->testConnection()) {
    echo "Auth failed\n";
    exit;
}
echo "Auth OK\n";

$segments = TextProcessor::splitForBluesky($text, 300);
echo "Segments: " . count($segments) . "\n";

foreach ($segments as $i => &$seg) {
    $seg['text'] .= sprintf(' (%d/%d)', $i + 1, count($segments));
}

echo "Creating post...\n";
$result = $bsky->createPost($segments[0]['text']);

if ($result) {
    echo "Success! URI: " . ($result['uri'] ?? 'unknown') . "\n";
    $pdo->exec('UPDATE queue SET status = "complete" WHERE id = ' . (int)$item['id']);
    $pdo->exec('UPDATE synced_posts SET status = "synced" WHERE x_post_id = "' . $item['x_post_id'] . '"');
} else {
    echo "Failed\n";
}
