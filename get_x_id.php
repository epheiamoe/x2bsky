<?php
require_once __DIR__ . '/vendor/autoload.php';

use X2BSky\Config;

Config::init(__DIR__ . '/.env');

$bearerToken = Config::get('X_BEARER_TOKEN', '');

$usernames = ['epheia_nyako', 'nyaepheia', 'epheia'];

foreach ($usernames as $username) {
    echo "Trying username: $username\n";

    $url = "https://api.twitter.com/2/users/by/username/" . str_replace('@', '', $username);
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

    print_r($data);
    echo "\n";
}
