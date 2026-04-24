<?php
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;
use X2BSky\Api\XApiClient;
use X2BSky\Api\BlueskyClient;

Config::init(__DIR__ . '/.env');

echo "Testing X API connection...\n";
$xClient = new XApiClient();
$result = $xClient->testConnection();
echo "X API: " . ($result ? "OK" : "FAILED") . "\n";

echo "\nTesting Bluesky connection...\n";
$bskyClient = new BlueskyClient();
$result = $bskyClient->testConnection();
echo "Bluesky: " . ($result ? "OK" : "FAILED") . "\n";
