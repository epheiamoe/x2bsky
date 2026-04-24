<?php

declare(strict_types=1);

namespace X2BSky\Queue;

use X2BSky\Config;
use X2BSky\Database;
use X2BSky\Logger;
use Predis\Client as RedisClient;

class QueueManager
{
    private static ?RedisClient $redis = null;
    private static bool $useRedis = false;

    public static function init(): void
    {
        $redisHost = Config::get('REDIS_HOST', '127.0.0.1');
        $redisPort = (int) Config::get('REDIS_PORT', 6379);
        $redisPass = Config::get('REDIS_REQUIREPASS', '');

        try {
            self::$redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => $redisHost,
                'port' => $redisPort,
                'password' => $redisPass ?: null,
                'timeout' => 2.0,
            ]);
            self::$redis->ping();
            self::$useRedis = true;
            Logger::info('Redis connected successfully');
        } catch (\Throwable $e) {
            Logger::warning('Redis not available, falling back to database queue', ['error' => $e->getMessage()]);
            self::$useRedis = false;
        }
    }

    public static function enqueue(string $jobId, string $xPostId, array $postData, int $priority = 0): bool
    {
        if (self::$useRedis && self::$redis) {
            return self::enqueueRedis($jobId, $xPostId, $postData, $priority);
        }
        return self::enqueueDb($jobId, $xPostId, $postData, $priority);
    }

    private static function enqueueRedis(string $jobId, string $xPostId, array $postData, int $priority): bool
    {
        try {
            $payload = json_encode([
                'job_id' => $jobId,
                'x_post_id' => $xPostId,
                'post_data' => $postData,
                'priority' => $priority,
                'attempts' => 0,
                'created_at' => time(),
            ]);

            $queueKey = $priority > 0 ? "x2bsky:queue:priority" : "x2bsky:queue:normal";
            self::$redis->rpush($queueKey, $payload);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Redis enqueue failed', ['error' => $e->getMessage()]);
            return self::enqueueDb($jobId, $xPostId, $postData, $priority);
        }
    }

    private static function enqueueDb(string $jobId, string $xPostId, array $postData, int $priority): bool
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('
                INSERT INTO queue (job_id, x_post_id, post_data, priority, process_after)
                VALUES (?, ?, ?, ?, datetime("now"))
            ');
            $stmt->execute([$jobId, $xPostId, json_encode($postData), $priority]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Database enqueue failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public static function dequeue(): ?array
    {
        if (self::$useRedis && self::$redis) {
            $result = self::dequeueRedis();
            if ($result !== null) {
                return $result;
            }
            $dbResult = self::dequeueDb();
            if ($dbResult !== null) {
                return $dbResult;
            }
            return null;
        }
        return self::dequeueDb();
    }

    private static function dequeueRedis(): ?array
    {
        try {
            $payload = self::$redis->lpop("x2bsky:queue:priority");
            if (!$payload) {
                $payload = self::$redis->lpop("x2bsky:queue:normal");
            }

            if (!$payload) {
                return null;
            }

            $data = json_decode($payload, true);
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;
            return $data;
        } catch (\Throwable $e) {
            Logger::error('Redis dequeue failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private static function dequeueDb(): ?array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('
                SELECT * FROM queue
                WHERE status = "pending"
                AND process_after <= datetime("now")
                AND attempts < max_attempts
                ORDER BY priority DESC, created_at ASC
                LIMIT 1
            ');
            $stmt->execute();
            $item = $stmt->fetch();

            if ($item) {
                $update = $pdo->prepare('UPDATE queue SET status = "processing" WHERE id = ?');
                $update->execute([$item['id']]);
                $item['post_data'] = json_decode($item['post_data'], true);
                return $item;
            }

            return null;
        } catch (\Throwable $e) {
            Logger::error('Database dequeue failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public static function markComplete(int $queueId): void
    {
        if (self::$useRedis && self::$redis) {
            return;
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('UPDATE queue SET status = "complete", processed_at = datetime("now") WHERE id = ?');
            $stmt->execute([$queueId]);
        } catch (\Throwable $e) {
            Logger::error('Failed to mark queue item complete', ['id' => $queueId]);
        }
    }

    public static function markFailed(int $queueId, string $error): void
    {
        if (self::$useRedis && self::$redis) {
            return;
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('
                UPDATE queue
                SET status = "failed", error_message = ?,
                    attempts = attempts + 1,
                    process_after = datetime("now", "+" || (POWER(2, attempts) * 60) || " seconds")
                WHERE id = ?
            ');
            $stmt->execute([$error, $queueId]);
        } catch (\Throwable $e) {
            Logger::error('Failed to mark queue item failed', ['id' => $queueId]);
        }
    }

    public static function getStats(): array
    {
        try {
            $pdo = Database::getInstance();
            $pending = $pdo->query('SELECT COUNT(*) FROM queue WHERE status = "pending"')->fetchColumn();
            $processing = $pdo->query('SELECT COUNT(*) FROM queue WHERE status = "processing"')->fetchColumn();
            $failed = $pdo->query('SELECT COUNT(*) FROM queue WHERE status = "failed"')->fetchColumn();

            return [
                'pending' => $pending,
                'processing' => $processing,
                'failed' => $failed,
                'use_redis' => self::$useRedis,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
