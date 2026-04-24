<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;

Config::init(__DIR__ . '/.env');

$bearerToken = Config::get('X_BEARER_TOKEN', '');
$userId = Config::get('X_USER_ID', '');

echo "Testing X API filters...\n\n";

// Test 1: With exclude=replies
echo "=== Test 1: exclude=replies ===\n";
$url = "https://api.twitter.com/2/users/$userId/tweets?max_results=10&exclude=replies&tweet.fields=id,text,created_at,referenced_tweets,reply_settings";
$headers = ["Authorization: Bearer $bearerToken"];
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
    ]
]);
$response = file_get_contents($url, false, $context);
$data = json_decode($response, true);

if (isset($data['data'])) {
    echo "Got " . count($data['data']) . " tweets\n";
    foreach ($data['data'] as $tweet) {
        $text = mb_substr($tweet['text'], 0, 60);
        $isReply = isset($tweet['referenced_tweets']);
        $refType = $isReply ? ($tweet['referenced_tweets'][0]['type'] ?? 'unknown') : 'original';
        echo "  [$refType] $text...\n";
    }
} else {
    echo "Error or no data\n";
    print_r($data);
}

echo "\n=== Test 2: With pagination to find RTs ===\n";
$url2 = "https://api.twitter.com/2/users/$userId/tweets?max_results=100&tweet.fields=id,text,created_at,referenced_tweets,edit_history_tweet_ids&exclude=replies";
$response2 = file_get_contents($url2, false, $context);
$data2 = json_decode($response2, true);

if (isset($data2['data'])) {
    $original = 0;
    $retweets = 0;
    $replies = 0;

    foreach ($data2['data'] as $tweet) {
        $text = $tweet['text'] ?? '';
        if (str_starts_with($text, 'RT @')) {
            $retweets++;
        } elseif (isset($tweet['referenced_tweets'])) {
            foreach ($tweet['referenced_tweets'] as $ref) {
                if ($ref['type'] === 'retweeted') {
                    $retweets++;
                } elseif ($ref['type'] === 'replied_to') {
                    $replies++;
                }
            }
        } else {
            $original++;
        }
    }

    echo "Total: " . count($data2['data']) . " tweets\n";
    echo "  Original tweets: $original\n";
    echo "  Retweets: $retweets\n";
    echo "  Replies (should be 0 with exclude=replies): $replies\n";
} else {
    echo "Error\n";
    print_r($data2);
}

echo "\n=== Test 3: Check reply_settings field ===\n";
$url3 = "https://api.twitter.com/2/users/$userId/tweets?max_results=5&tweet.fields=id,text,reply_settings";
$response3 = file_get_contents($url3, false, $context);
$data3 = json_decode($response3, true);
if (isset($data3['data'])) {
    foreach ($data3['data'] as $tweet) {
        echo "Reply settings: " . ($tweet['reply_settings'] ?? 'not set') . "\n";
    }
}
