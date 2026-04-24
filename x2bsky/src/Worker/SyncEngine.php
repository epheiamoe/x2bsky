<?php

declare(strict_types=1);

namespace X2BSky\Worker;

use X2BSky\Config;
use X2BSky\Database;
use X2BSky\Logger;
use X2BSky\Api\XApiClient;
use X2BSky\Api\BlueskyClient;
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
            $stmt = $pdo->prepare('UPDATE sync_jobs SET status = "completed", completed_at = datetime("now"), error_message = "No new tweets" WHERE job_id = ?');
            $stmt->execute([$jobId]);
            Logger::info('No new tweets to sync', ['job_id' => $jobId]);
            return ['job_id' => $jobId, 'status' => 'completed', 'processed' => 0];
        }

        $filtered = $this->filterNewTweets($tweets);

        if (empty($filtered)) {
            $stmt = $pdo->prepare('UPDATE sync_jobs SET status = "completed", completed_at = datetime("now"), error_message = "All tweets already synced" WHERE job_id = ?');
            $stmt->execute([$jobId]);
            Logger::info('All tweets already synced', ['job_id' => $jobId]);
            return ['job_id' => $jobId, 'status' => 'completed', 'processed' => 0];
        }

        $stmt = $pdo->prepare('UPDATE sync_jobs SET total_posts = ? WHERE job_id = ?');
        $stmt->execute([count($filtered), $jobId]);

        foreach ($filtered as $tweet) {
            $this->enqueuePost($jobId, $tweet);
        }

        $newestId = $filtered[0]['id'];
        $this->saveSinceId($newestId);

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
                $textHash = md5($tweet['text']);
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

        $stmt = $pdo->prepare('
            INSERT INTO synced_posts (x_post_id, x_post_url, text_hash, text_preview, status)
            VALUES (?, ?, ?, ?, "pending")
        ');
        $url = 'https://x.com/i/status/' . $tweet['id'];
        $stmt->execute([
            $tweet['id'],
            $url,
            md5($tweet['text']),
            mb_substr($tweet['text'], 0, 100)
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
            $segments = $this->splitPost($postData['text']);

            if (!empty($postData['_media'])) {
                $mediaResult = MediaProcessor::processMedia($postData['_media'], $xPostId);

                foreach ($segments as $i => &$segment) {
                    $segment['_mediaBlob'] = null;
                    $segment['_mediaAlt'] = '';

                    foreach ($mediaResult as $media) {
                        if (!empty($media['blobs'])) {
                            $segment['_mediaBlob'] = $media['blobs'][0]['blob'];
                            $segment['_mediaAlt'] = $media['blobs'][0]['alt'] ?? '';
                            break;
                        }
                    }
                }
            }

            foreach ($segments as $i => &$segment) {
                $segment['text'] = $segment['text'];
                if (count($segments) > 1) {
                    $segment['text'] .= sprintf(' (%d/%d)', $i + 1, count($segments));
                }
            }

            $results = $this->bskyClient->createThread($segments);

            if (!empty($results)) {
                $pdo = Database::getInstance();
                $stmt = $pdo->prepare('UPDATE synced_posts SET status = "synced", synced_at = datetime("now") WHERE x_post_id = ?');
                $stmt->execute([$xPostId]);

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
        }
    }

    private function splitPost(string $text): array
    {
        $maxChars = 300;
        $segments = [];

        $paragraphs = preg_split('/\n\n+/', $text);

        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        $currentSegment = '';
        $currentLength = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $paraLength = mb_strlen($paragraph);

            if ($paraLength <= $maxChars && $currentLength + $paraLength + 2 <= $maxChars) {
                $currentSegment .= ($currentSegment ? "\n\n" : '') . $paragraph;
                $currentLength += ($currentSegment ? 2 : 0) + $paraLength;
            } elseif ($paraLength > $maxChars) {
                if ($currentSegment) {
                    $segments[] = $currentSegment;
                    $currentSegment = '';
                    $currentLength = 0;
                }

                $sentences = preg_split('/(?<=[.!?。！？])\s+/', $paragraph);
                if ($sentences === false) {
                    $sentences = [$paragraph];
                }

                $currentSubSegment = '';
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence === '') {
                        continue;
                    }

                    $sentenceLen = mb_strlen($sentence);

                    if ($sentenceLen <= $maxChars && $currentLength + $sentenceLen + 1 <= $maxChars) {
                        $currentSubSegment .= ($currentSubSegment ? ' ' : '') . $sentence;
                        $currentLength += ($currentSubSegment ? 1 : 0) + $sentenceLen;
                    } else {
                        if ($currentSubSegment) {
                            $segments[] = $currentSubSegment;
                        }
                        $currentSubSegment = $sentence;
                        $currentLength = $sentenceLen;
                    }
                }

                if ($currentSubSegment) {
                    $currentSegment = $currentSubSegment;
                    $currentLength = mb_strlen($currentSubSegment);
                }
            } else {
                if ($currentSegment) {
                    $segments[] = $currentSegment;
                }
                $currentSegment = $paragraph;
                $currentLength = $paraLength;
            }
        }

        if ($currentSegment) {
            $segments[] = $currentSegment;
        }

        if (empty($segments)) {
            $segments = [mb_substr($text, 0, $maxChars)];
        }

        return array_map(fn($s) => ['text' => $s], $segments);
    }

    private function getLastSinceId(): ?string
    {
        $pdo = Database::getInstance();
        $stmt = $pdo->query('SELECT x_post_id FROM synced_posts ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ? $row['x_post_id'] : null;
    }

    private function saveSinceId(string $sinceId): void
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
        $stmt = $pdo->prepare('UPDATE sync_jobs SET status = "completed", completed_at = datetime("now") WHERE job_id = ?');
        $stmt->execute([$jobId]);
    }
}
