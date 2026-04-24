<?php
require_once __DIR__ . '/vendor/autoload.php';
use X2BSky\Config;

Config::init(__DIR__ . '/.env');

$bearerToken = Config::get('X_BEARER_TOKEN', '');
$userId = Config::get('X_USER_ID', '');

// Test fetching a specific tweet with media
$tweetId = '2047323584050397397';
echo "=== Test: Get tweet $tweetId with media ===\n";

$url = "https://api.twitter.com/2/tweets/$tweetId?tweet.fields=id,text,created_at,attachments,entities&expansions=attachments.media_keys&media.fields=url,preview_image_url,type,duration_ms,width,height,alt_text,media_key";

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

if (isset($data['errors'])) {
    echo "Error:\n";
    print_r($data);
    exit;
}

echo "Tweet text: " . ($data['data']['text'] ?? 'N/A') . "\n\n";

if (isset($data['includes']['media'])) {
    echo "Media found:\n";
    foreach ($data['includes']['media'] as $media) {
        echo "  Type: " . ($media['type'] ?? 'unknown') . "\n";
        echo "  URL: " . ($media['url'] ?? 'N/A') . "\n";
        echo "  Preview: " . ($media['preview_image_url'] ?? 'N/A') . "\n";
        echo "  Alt text: " . ($media['alt_text'] ?? 'N/A') . "\n";
        echo "  Size: " . ($media['width'] ?? '?') . "x" . ($media['height'] ?? '?') . "\n";
        echo "\n";

        // Try to download
        $imgUrl = $media['url'] ?? $media['preview_image_url'] ?? '';
        if ($imgUrl) {
            echo "=== Testing download from: $imgUrl ===\n";
            $imgData = @file_get_contents($imgUrl);
            if ($imgData) {
                $size = strlen($imgData);
                echo "Downloaded $size bytes successfully!\n";

                // Save test file
                $ext = pathinfo(parse_url($imgUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
                $testFile = __DIR__ . "/test_image.$ext";
                file_put_contents($testFile, $imgData);
                echo "Saved to: $testFile\n";

                // Check if it's a valid image
                $imgInfo = @getimagesize($testFile);
                if ($imgInfo) {
                    echo "Valid image: {$imgInfo[0]}x{$imgInfo[1]}, type: {$imgInfo['mime']}\n";
                }
            } else {
                echo "Failed to download\n";
            }
        }
    }
} else {
    echo "No media in this tweet\n";
}

echo "\n=== Full API response ===\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
