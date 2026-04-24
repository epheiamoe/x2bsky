<?php

declare(strict_types=1);

namespace X2BSky\Api;

use X2BSky\Config;
use X2BSky\Logger;

class BlueskyClient
{
    private string $handle;
    private string $password;
    private ?string $accessToken = null;
    private ?string $did = null;
    private string $sessionFile;

    public function __construct()
    {
        $this->handle = Config::get('BSKY_HANDLE', '');
        $this->password = Config::get('BSKY_APP_PASSWORD', Config::get('BSKY_PASSWORD', ''));
        $this->sessionFile = dirname(__DIR__, 2) . '/data/bsky_session.json';
    }

    public function authenticate(): bool
    {
        $savedSession = $this->loadSession();

        if ($savedSession && $this->isSessionValid($savedSession)) {
            $this->accessToken = $savedSession['accessJwt'] ?? $savedSession['accessToken'] ?? null;
            $this->did = $savedSession['did'] ?? null;
            return $this->accessToken !== null;
        }

        return $this->doAuthenticate();
    }

    private function doAuthenticate(): bool
    {
        $url = 'https://bsky.social/xrpc/com.atproto.server.createSession';
        $data = [
            'identifier' => $this->handle,
            'password' => $this->password,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => json_encode($data),
                'ignore_errors' => true,
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        $result = json_decode($response, true);

        if (isset($result['accessJwt'])) {
            $this->accessToken = $result['accessJwt'];
            $this->did = $result['did'];
            $this->saveSession($result);
            Logger::info('Bluesky authenticated successfully');
            return true;
        }

        Logger::error('Bluesky authentication failed', ['response' => $result]);
        return false;
    }

    private function loadSession(): ?array
    {
        if (!file_exists($this->sessionFile)) {
            return null;
        }

        $data = file_get_contents($this->sessionFile);
        return json_decode($data, true);
    }

    private function isSessionValid(array $session): bool
    {
        if (!isset($session['expiresAt'])) {
            return false;
        }
        return time() < ($session['expiresAt'] - 300);
    }

    private function saveSession(array $result): void
    {
        $session = [
            'accessJwt' => $result['accessJwt'] ?? null,
            'did' => $result['did'] ?? null,
            'handle' => $result['handle'] ?? null,
            'expiresAt' => time() + 3600,
        ];
        file_put_contents($this->sessionFile, json_encode($session));
    }

    private function getHeaders(): array
    {
        return [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
        ];
    }

    private function resolveDid(): ?string
    {
        $session = $this->loadSession();
        return $session['did'] ?? null;
    }

    private function apiCall(string $method, string $endpoint, array $data = []): ?array
    {
        if (!$this->accessToken && !$this->authenticate()) {
            return null;
        }

        if (!$this->did) {
            $this->did = $this->resolveDid();
        }

        $url = 'https://bsky.social/xrpc/' . $endpoint;

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->getHeaders()),
                'content' => json_encode($data),
                'ignore_errors' => true,
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        $result = json_decode($response, true);

        if (strpos($http_response_header[0] ?? '', '200') !== false) {
            return $result;
        }

        if (isset($result['error']) && $result['error'] === 'TokenExpired') {
            if ($this->doAuthenticate()) {
                return $this->apiCall($method, $endpoint, $data);
            }
        }

        Logger::error('Bluesky API call failed', [
            'method' => $method,
            'endpoint' => $endpoint,
            'result' => $result
        ]);

        return null;
    }

    public function uploadBlob(string $filePath): ?array
    {
        if (!$this->accessToken && !$this->authenticate()) {
            return null;
        }

        $mimeType = mime_content_type($filePath);
        $fileData = file_get_contents($filePath);
        $base64Data = base64_encode($fileData);

        $url = 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob';

        $boundary = 'x2bsky_' . bin2hex(random_bytes(8));

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . basename($filePath) . "\"\r\n\r\n";
        $body .= $fileData . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        $result = json_decode($response, true);

        if (isset($result['blob'])) {
            return $result['blob'];
        }

        Logger::error('Blob upload failed', ['response' => $result]);
        return null;
    }

    public function createPost(string $text, ?array $embed = null, ?string $replyTo = null): ?array
    {
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => date('c'),
        ];

        if ($embed) {
            $record['embed'] = $embed;
        }

        if ($replyTo) {
            $record['reply'] = ['parent' => $replyTo, 'root' => $replyTo];
        }

        $data = [
            'repo' => $this->did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ];

        return $this->apiCall('POST', 'com.atproto.repo.createRecord', $data);
    }

    public function createThread(array $posts): array
    {
        $results = [];
        $parentUri = null;
        $rootUri = null;

        foreach ($posts as $index => $post) {
            $embed = null;
            if (isset($post['_mediaBlob'])) {
                $embed = [
                    '$type' => 'app.bsky.embed.images',
                    'images' => [[
                        'image' => $post['_mediaBlob'],
                        'alt' => $post['_mediaAlt'] ?? '',
                    ]]
                ];
            }

            $replyRef = null;
            if ($parentUri) {
                $replyRef = ['parent' => $parentUri, 'root' => $rootUri];
            }

            $result = $this->createPost($post['text'], $embed, $replyRef ? json_encode($replyRef) : null);

            if ($result) {
                $uri = $result['uri'];
                if ($index === 0) {
                    $rootUri = $uri;
                }
                $parentUri = $uri;
                $results[] = $result;
            } else {
                Logger::error('Failed to create post in thread', ['index' => $index]);
            }
        }

        return $results;
    }

    public function testConnection(): bool
    {
        return $this->authenticate();
    }
}
