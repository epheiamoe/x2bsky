<?php

declare(strict_types=1);

namespace X2BSky\Worker;

use X2BSky\Config;
use X2BSky\Database;
use X2BSky\Logger;
use X2BSky\Api\XApiClient;
use X2BSky\Api\BlueskyClient;
use X2BSky\Api\TextProcessor;
use X2BSky\Queue\QueueManager;
use X2BSky\Media\MediaProcessor;

class SyncEngine
{
    private XApiClient $xClient;
    private BlueskyClient $bskyClient;

    public function __construct()
    {
        $this->xClient = new XApiClient();
        $this->bskyClient = new BlueskyClient();
    }

    public function sync(int $count = 10): array
    {
        $jobId = 'job_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $startedAt = time();

        Logger::info('Starting sync', ['job_id' => $jobId, 'count' => $count]);

        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('INSERT INTO sync_jobs (job_id, status, total_posts) VALUES (?, "running", ?)');
        $stmt->execute([$jobId, $count]);

        $lastSinceId = $this->getLastSinceId();

        $tweets = $this->xClient->getUserTweets($count, $lastSinceId);

        if (empty($tweets)) {
            $stmt = $pdo->prepare('UPDATE sync_jobs SET status = "completed", completed_at = NOW(), error_message = "No new tweets" WHERE job_id = ?');
            $stmt->execute([$jobId]);
            Logger::info('No new tweets to sync', ['job_id' => $jobId]);
            return ['job_id' => $jobId, 'status' => 'completed', 'processed' => 0];
        }

        $filtered = $this->filterNewTweets($tweets);

        if (empty($filtered)) {
            $stmt = $pdo->prepare('UPDATE sync_jobs SET status = "completed", completed_at = NOW(), error_message = "All tweets already synced" WHERE job_id = ?');
            $stmt->execute([$jobId]);
            Logger::info('All tweets already synced', ['job_id' => $jobId]);
            return ['job_id' => $jobId, 'status' => 'completed', 'processed' => 0];
        }

        $stmt = $pdo->prepare('UPDATE sync_jobs SET total_posts = ? WHERE job_id = ?');
        $stmt->execute([count($filtered), $jobId]);

        foreach ($filtered as $tweet) {
            $this->enqueuePost($jobId, $tweet);
        }

        Logger::info('Sync job created', ['job_id' => $jobId, 'posts_to_sync' => count($filtered)]);

        return [
            'job_id' => $jobId,
            'status' => 'queued',
            'total' => count($filtered),
            'newest_id' => $newestId,
        ];
    }

    private function filterNewTweets(array $tweets): array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT x_post_id FROM synced_posts');
        $stmt->execute();
        $existingIds = array_column($stmt->fetchAll(), 'x_post_id');

        $filtered = [];
        foreach ($tweets as $tweet) {
            if (!in_array($tweet['id'], $existingIds)) {
                $fullText = $tweet['_full_text'] ?? $tweet['text'];
                $textHash = md5($fullText);
                $stmt = $pdo->prepare('SELECT id FROM synced_posts WHERE text_hash = ?');
                $stmt->execute([$textHash]);
                if (!$stmt->fetch()) {
                    $filtered[] = $tweet;
                }
            }
        }

        return $filtered;
    }

    private function enqueuePost(string $jobId, array $tweet): void
    {
        $pdo = Database::getInstance();
        $fullText = $tweet['_full_text'] ?? $tweet['text'];

        $stmt = $pdo->prepare('
            INSERT INTO synced_posts (x_post_id, x_post_url, text_hash, text_preview, status)
            VALUES (?, ?, ?, ?, "pending")
        ');
        $url = 'https://x.com/i/status/' . $tweet['id'];
        $stmt->execute([
            $tweet['id'],
            $url,
            md5($fullText),
            mb_substr($fullText, 0, 100)
        ]);

        QueueManager::enqueue($jobId, $tweet['id'], $tweet);
    }

    public function processQueueItem(array $item): bool
    {
        $jobId = $item['job_id'];
        $xPostId = $item['x_post_id'];
        $postData = $item['post_data'];

        Logger::info('Processing queue item', ['job_id' => $jobId, 'x_post_id' => $xPostId]);

        try {
            $text = $postData['_full_text'] ?? $postData['text'];
            $segments = TextProcessor::splitForBluesky($text, 300);
            $segments = TextProcessor::addThreadNotation($segments);

            if (!empty($postData['_media'])) {
                $mediaResult = MediaProcessor::processMedia($postData['_media'], $xPostId);

                foreach ($mediaResult as $m => $media) {
                    if (!empty($media['blobs']) && $m === 0) {
                        $segments[0]['_mediaBlob'] = $media['blobs'][0]['blob'];
                        $segments[0]['_mediaAlt'] = $media['blobs'][0]['alt'] ?? '';
                    }
                }
            }

            $results = $this->bskyClient->createThread($segments);

            if (!empty($results)) {
                $pdo = Database::getInstance();
                $stmt = $pdo->prepare('UPDATE synced_posts SET status = "synced", synced_at = NOW() WHERE x_post_id = ?');
                $stmt->execute([$xPostId]);

                $finalUri = end($results)['uri'];
                $stmt = $pdo->prepare('SELECT id FROM posts WHERE x_post_id = ?');
                $stmt->execute([$xPostId]);
                $postRow = $stmt->fetch();
                $masterPostId = $postRow ? $postRow['id'] : null;

                if ($masterPostId) {
                    $bskyHandle = Config::get('BSKY_HANDLE', 'your_handle');
                    $platformUrl = 'https://bsky.app/profile/' . $bskyHandle . '/post/' . basename($finalUri);
                    $stmt = $pdo->prepare('INSERT INTO synced_destinations (post_id, platform, platform_post_url, platform_post_uri, status, synced_at) VALUES (?, ?, ?, ?, ?, NOW())');
                    $stmt->execute([$masterPostId, 'bluesky', $platformUrl, $finalUri, 'synced']);

                    foreach ($postData['_media'] ?? [] as $media) {
                        $stmt = $pdo->prepare('INSERT INTO post_media (post_id, platform, media_type, original_url, local_path, alt_text) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$masterPostId, 'bluesky', $media['type'] ?? 'image', $platformUrl, null, $media['alt_text'] ?? '']);
                    }
                }

                $this->advanceSinceId($xPostId);

                Logger::info('Post synced successfully', ['x_post_id' => $xPostId, 'bsky_posts' => count($results)]);
                return true;
            }

            throw new \RuntimeException('Failed to create Bluesky posts');
        } catch (\Throwable $e) {
            Logger::error('Failed to process post', ['x_post_id' => $xPostId, 'error' => $e->getMessage()]);

            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('UPDATE synced_posts SET status = "failed" WHERE x_post_id = ?');
            $stmt->execute([$xPostId]);

            return false;
        } finally {
            MediaProcessor::cleanup();
        }
    }

    private function getLastSinceId(): ?string
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query('SELECT x_post_id FROM synced_posts ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ? $row['x_post_id'] : null;
    }

    private function advanceSinceId(string $sinceId): void
    {
        $current = $this->readSinceIdFromEnv();
        if ($current !== null && $sinceId <= $current) {
            return;
        }
        $this->writeSinceIdToEnv($sinceId);
    }

    private function readSinceIdFromEnv(): ?string
    {
        $configPath = dirname(__DIR__, 2) . '/.env';
        if (!file_exists($configPath)) {
            return null;
        }
        foreach (file($configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), 'X_SINCE_ID=')) {
                return trim(substr($line, strpos($line, '=') + 1));
            }
        }
        return null;
    }

    private function writeSinceIdToEnv(string $sinceId): void
    {
        $configPath = dirname(__DIR__, 2) . '/.env';
        $content = file_get_contents($configPath);
        $lines = explode("\n", $content);
        $found = false;

        foreach ($lines as &$line) {
            if (str_starts_with(trim($line), 'X_SINCE_ID=')) {
                $line = 'X_SINCE_ID=' . $sinceId;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $lines[] = 'X_SINCE_ID=' . $sinceId;
        }

        file_put_contents($configPath, implode("\n", $lines));
    }

    public function getJobStatus(string $jobId): ?array
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('SELECT * FROM sync_jobs WHERE job_id = ?');
        $stmt->execute([$jobId]);
        return $stmt->fetch() ?: null;
    }

    public function updateJobProgress(string $jobId, int $processed, bool $success): void
    {
        $pdo = Database::getInstance();
        $field = $success ? 'successful_posts' : 'failed_posts';
        $stmt = $pdo->prepare("UPDATE sync_jobs SET processed_posts = processed_posts + 1, {$field} = {$field} + 1 WHERE job_id = ?");
        $stmt->execute([$jobId]);
    }

    public function completeJob(string $jobId): void
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare('UPDATE sync_jobs SET status = "completed", completed_at = NOW() WHERE job_id = ?');
        $stmt->execute([$jobId]);
    }
}
