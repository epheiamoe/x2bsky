<?php

declare(strict_types=1);

namespace X2BSky;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getInstance(): PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }
        return self::$pdo;
    }

    private static function connect(): void
    {
        $dsn = Config::getDSN();
        $type = Config::get('DB_TYPE', 'sqlite');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($type === 'sqlite') {
            $dbPath = Config::get('DB_PATH', '/data/x2bsky.db');
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        try {
            self::$pdo = new PDO($dsn, Config::get('DB_USER'), Config::get('DB_PASS'), $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw $e;
        }

        self::initSchema();
    }

    private static function initSchema(): void
    {
        $type = Config::get('DB_TYPE', 'sqlite');
        $pdo = self::$pdo;

        if ($type === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
        }

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS synced_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                x_post_id TEXT UNIQUE NOT NULL,
                x_post_url TEXT,
                text_hash TEXT NOT NULL,
                text_preview TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT DEFAULT "pending"
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sync_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id TEXT UNIQUE NOT NULL,
                status TEXT DEFAULT "pending",
                total_posts INTEGER DEFAULT 0,
                processed_posts INTEGER DEFAULT 0,
                successful_posts INTEGER DEFAULT 0,
                failed_posts INTEGER DEFAULT 0,
                error_message TEXT,
                started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id TEXT NOT NULL,
                x_post_id TEXT NOT NULL,
                post_data TEXT NOT NULL,
                priority INTEGER DEFAULT 0,
                attempts INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 5,
                status TEXT DEFAULT "pending",
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                process_after DATETIME,
                processed_at DATETIME
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                x_post_id TEXT NOT NULL,
                original_url TEXT NOT NULL,
                local_path TEXT,
                media_type TEXT,
                status TEXT DEFAULT "pending",
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id TEXT,
                level TEXT NOT NULL,
                message TEXT NOT NULL,
                context TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_synced_x_post_id ON synced_posts(x_post_id)
        ');
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_queue_status ON queue(status)
        ');
        $pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_logs_job_id ON logs(job_id)
        ');
    }

    public static function resetInstance(): void
    {
        self::$pdo = null;
    }
}
