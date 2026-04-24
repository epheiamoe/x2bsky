<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;

Config::init(__DIR__ . '/.env');

$session = json_decode(file_get_contents(__DIR__ . '/data/bsky_session.json'), true);
$token = $session['accessJwt'] ?? '';

$url = 'https://bsky.social/xrpc/app.bsky.feed.getAuthorFeed?actor=watakushi.desuwa.org&limit=10';
$headers = ["Authorization: Bearer $token"];

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
    ]
]);

$response = file_get_contents($url, false, $context);
$data = json_decode($response, true);

if (isset($data['feed'])) {
    foreach (array_slice($data['feed'], 0, 10) as $item) {
        $post = $item['post'];
        $text = $post['record']['text'] ?? '';
        $uri = $post['uri'] ?? '';
        $createdAt = $post['record']['createdAt'] ?? '';
        echo "---" . PHP_EOL;
        echo "URI: $uri" . PHP_EOL;
        echo "Time: $createdAt" . PHP_EOL;
        echo "Text: " . mb_substr($text, 0, 200) . PHP_EOL;
        echo PHP_EOL;
    }
} else {
    echo "No feed data or error:" . PHP_EOL;
    print_r($data);
}
