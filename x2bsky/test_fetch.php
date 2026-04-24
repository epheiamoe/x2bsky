<?php
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Api\XApiClient;

Config::init(__DIR__ . '/.env');

$xClient = new XApiClient();
$tweets = $xClient->getUserTweets(5);

echo "Fetched " . count($tweets) . " tweets:\n\n";
foreach ($tweets as $i => $tweet) {
    echo "--- Tweet " . ($i+1) . " ---\n";
    echo "ID: " . $tweet['id'] . "\n";
    echo "Text: " . mb_substr($tweet['text'], 0, 100) . "...\n";
    echo "Created: " . $tweet['created_at'] . "\n";
    if (!empty($tweet['_media'])) {
        echo "Media: " . count($tweet['_media']) . " items\n";
        foreach ($tweet['_media'] as $m) {
            echo "  - Type: " . $m['type'] . ", URL: " . ($m['url'] ?? 'N/A') . "\n";
        }
    }
    echo "\n";
}
