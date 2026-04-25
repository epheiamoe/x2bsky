<?php

declare(strict_types=1);

namespace X2BSky\Api;

use X2BSky\Config;
use X2BSky\Logger;

class XApiClient
{
    private string $bearerToken;
    private string $consumerKey;
    private string $consumerSecret;
    private string $accessToken;
    private string $accessTokenSecret;
    private string $userId;
    private ?string $lastSinceId = null;

    public function __construct()
    {
        $this->bearerToken = Config::get('X_BEARER_TOKEN', '');
        $this->consumerKey = Config::get('X_CONSUMER_KEY', '');
        $this->consumerSecret = Config::get('X_SECRET_KEY', '');
        $this->accessToken = Config::get('X_ACCESS_TOKEN', '');
        $this->accessTokenSecret = Config::get('X_ACCESS_TOKEN_SECRET', '');
        $this->userId = Config::get('X_USER_ID', '');
    }

    private function getOAuthHeaders(string $method, string $url, array $params = []): array
    {
        $oauth = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $this->accessToken,
            'oauth_version' => '1.0',
        ];

        $signatureBase = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(
            http_build_query(array_merge($oauth, $params))
        );

        $signingKey = rawurlencode($this->consumerSecret) . '&' . rawurlencode($this->accessTokenSecret);
        $signature = base64_encode(
            hash_hmac('sha1', $signatureBase, $signingKey, true)
        );

        $oauth['oauth_signature'] = $signature;

        $authHeader = 'OAuth ' . implode(', ', array_map(
            fn($k, $v) => rawurlencode($k) . '="' . rawurlencode($v) . '"',
            array_keys($oauth),
            $oauth
        ));

        return [
            "Authorization: Bearer {$this->bearerToken}",
            "Authorization: {$authHeader}",
            'Content-Type: application/json',
        ];
    }

    public function getUserTweets(int $maxResults = 10, ?string $sinceId = null): array
    {
        $url = "https://api.twitter.com/2/users/{$this->userId}/tweets";

        $params = [
            'max_results' => min(max($maxResults, 5), 100),
            'tweet.fields' => 'id,text,created_at,entities,attachments,possibly_sensitive,referenced_tweets,edit_history_tweet_ids',
            'expansions' => 'attachments.media_keys,referenced_tweets.id',
            'media.fields' => 'url,preview_image_url,type,duration_ms,width,height,alt_text,media_key',
        ];

        if ($sinceId) {
            $params['since_id'] = $sinceId;
        }

        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        $headers = [
            "Authorization: Bearer {$this->bearerToken}",
            'Content-Type: application/json',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ]
        ]);

        $response = file_get_contents($fullUrl, false, $context);

        if ($response === false) {
            Logger::error('X API request failed', ['url' => $fullUrl]);
            return [];
        }

        $data = json_decode($response, true);

        if (isset($data['errors'])) {
            Logger::error('X API error', ['errors' => $data['errors']]);
            return [];
        }

        if (!isset($data['data'])) {
            Logger::warning('X API returned no data', ['response' => $data]);
            return [];
        }

        $tweets = $data['data'] ?? [];
        $includes = $data['includes'] ?? [];
        $medias = $includes['media'] ?? [];

        $mediaMap = [];
        foreach ($medias as $media) {
            $mediaMap[$media['media_key']] = $media;
        }

        foreach ($tweets as &$tweet) {
            $tweet['_media'] = [];
            if (isset($tweet['attachments']['media_keys'])) {
                foreach ($tweet['attachments']['media_keys'] as $key) {
                    if (isset($mediaMap[$key])) {
                        $tweet['_media'][] = $mediaMap[$key];
                    }
                }
            }
        }

        Logger::info('Fetched tweets', ['count' => count($tweets)]);
        return $tweets;
    }

    public function fetchUserTweets(int $maxResults = 20, ?string $sinceId = null, array $exclude = []): array
    {
        $url = "https://api.twitter.com/2/users/{$this->userId}/tweets";

        $params = [
            'max_results' => min(max($maxResults, 5), 100),
            'tweet.fields' => 'id,text,created_at,entities,attachments,possibly_sensitive,referenced_tweets,edit_history_tweet_ids,author_id',
            'expansions' => 'attachments.media_keys,referenced_tweets.id',
            'media.fields' => 'url,preview_image_url,type,duration_ms,width,height,alt_text,media_key',
        ];

        if ($sinceId) {
            $params['since_id'] = $sinceId;
        }

        if (!empty($exclude)) {
            $params['exclude'] = implode(',', $exclude);
        }

        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        $headers = [
            "Authorization: Bearer {$this->bearerToken}",
            'Content-Type: application/json',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ]
        ]);

        $response = file_get_contents($fullUrl, false, $context);

        if ($response === false) {
            Logger::error('X API fetch failed', ['url' => $fullUrl]);
            return [];
        }

        $data = json_decode($response, true);

        if (isset($data['errors'])) {
            Logger::error('X API error', ['errors' => $data['errors']]);
            return [];
        }

        if (!isset($data['data'])) {
            return [];
        }

        $tweets = $data['data'] ?? [];
        $includes = $data['includes'] ?? [];
        $medias = $includes['media'] ?? [];
        $includesTweets = $includes['tweets'] ?? [];

        $mediaMap = [];
        foreach ($medias as $media) {
            $mediaMap[$media['media_key']] = $media;
        }

        $tweetMap = [];
        foreach ($includesTweets as $t) {
            $tweetMap[$t['id']] = $t;
        }

        $filteredTweets = [];
        foreach ($tweets as $tweet) {
            $postType = $this->getPostType($tweet);

            if ($postType === 'replied_to') {
                continue;
            }

            $tweet['_post_type'] = $postType;
            $tweet['_media'] = [];
            $tweet['_quoted_url'] = null;

            if ($postType === 'retweeted' && isset($tweet['referenced_tweets'])) {
                foreach ($tweet['referenced_tweets'] as $ref) {
                    if ($ref['type'] === 'retweeted' && isset($ref['id'])) {
                        $originalTweetId = $ref['id'];

                        if (isset($tweetMap[$originalTweetId])) {
                            $originalTweet = $tweetMap[$originalTweetId];
                            if (isset($originalTweet['attachments']['media_keys'])) {
                                foreach ($originalTweet['attachments']['media_keys'] as $key) {
                                    if (isset($mediaMap[$key])) {
                                        $tweet['_media'][] = $mediaMap[$key];
                                    }
                                }
                            }
                        }
                        break;
                    }
                }
            } elseif (isset($tweet['attachments']['media_keys'])) {
                foreach ($tweet['attachments']['media_keys'] as $key) {
                    if (isset($mediaMap[$key])) {
                        $tweet['_media'][] = $mediaMap[$key];
                    }
                }
            }

            if ($postType === 'quoted' && isset($tweet['entities']['urls'])) {
                foreach ($tweet['entities']['urls'] as $url) {
                    if (!empty($url['expanded_url']) && strpos($url['expanded_url'], '/status/') !== false) {
                        $tweet['_quoted_url'] = $url['expanded_url'];
                        break;
                    }
                }
            }

            $filteredTweets[] = $tweet;
        }

        Logger::info('Fetched and filtered tweets', [
            'total' => count($tweets),
            'filtered' => count($filteredTweets)
        ]);

        return $filteredTweets;
    }

    private function getPostType(array $tweet): string
    {
        if (!isset($tweet['referenced_tweets'])) {
            return 'original';
        }

        foreach ($tweet['referenced_tweets'] as $ref) {
            if ($ref['type'] === 'replied_to') {
                return 'replied_to';
            }
            if ($ref['type'] === 'retweeted') {
                return 'retweeted';
            }
            if ($ref['type'] === 'quoted') {
                return 'quoted';
            }
        }

        return 'original';
    }

    public function downloadMedia(string $url, string $savePath): bool
    {
        $ch = curl_init($url);
        $fp = fopen($savePath, 'wb');

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$result || $httpCode !== 200) {
            @unlink($savePath);
            return false;
        }

        return true;
    }

    public function testConnection(): bool
    {
        try {
            $tweets = $this->getUserTweets(5);
            return true;
        } catch (\Throwable $e) {
            Logger::error('X API connection test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
