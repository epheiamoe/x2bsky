<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;
use X2BSky\Api\XApiClient;

Config::init(__DIR__ . '/.env');

$xClient = new XApiClient();
$tweets = $xClient->fetchUserTweets(10);

echo "Fetched " . count($tweets) . " tweets\n\n";

foreach ($tweets as $tweet) {
    $type = $tweet['_post_type'] ?? 'unknown';
    $text = mb_substr($tweet['text'] ?? '', 0, 60);
    echo "[$type] $text...\n";
}
