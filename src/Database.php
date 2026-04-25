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
        $type = Config::get('DB_TYPE', 'mysql');
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $dbname = Config::get('DB_NAME', 'x2bsky');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($type === 'mysql') {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        } else {
            $dbPath = Config::get('DB_PATH', '/data/x2bsky.db');
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dsn = "sqlite:{$dbPath}";
            $options[PDO::ATTR_EMULATE_PREPARES] = true;
        }

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw $e;
        }

        self::initSchema();
    }

    private static function initSchema(): void
    {
        $type = Config::get('DB_TYPE', 'mysql');
        $pdo = self::$pdo;

        if ($type === 'mysql') {
            $pdo->exec('SET sql_mode = ""');
        } else {
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
        }

        if ($type === 'mysql') {
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS posts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    x_post_id VARCHAR(64) NOT NULL,
                    x_post_url VARCHAR(512),
                    x_author VARCHAR(128),
                    text TEXT NOT NULL,
                    text_hash VARCHAR(64) NOT NULL,
                    is_retweet TINYINT(1) DEFAULT 0,
                    is_quote TINYINT(1) DEFAULT 0,
                    original_author VARCHAR(128),
                    x_created_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_x_post_id (x_post_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS post_media (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    post_id BIGINT UNSIGNED NOT NULL,
                    platform VARCHAR(16) NOT NULL,
                    media_type VARCHAR(16) NOT NULL DEFAULT "image",
                    original_url VARCHAR(1024) NOT NULL,
                    local_path VARCHAR(1024),
                    alt_text VARCHAR(512),
                    width INT,
                    height INT,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS synced_destinations (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    post_id BIGINT UNSIGNED NOT NULL,
                    platform VARCHAR(16) NOT NULL,
                    platform_post_url VARCHAR(1024),
                    platform_post_uri VARCHAR(512),
                    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status VARCHAR(16) DEFAULT "pending",
                    error_message TEXT,
                    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS settings (
                    setting_key VARCHAR(128) PRIMARY KEY,
                    value TEXT NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    job_id VARCHAR(64),
                    level VARCHAR(16) NOT NULL,
                    message TEXT NOT NULL,
                    context TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS fetched_posts (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    x_post_id VARCHAR(64) NOT NULL,
                    x_post_url VARCHAR(512),
                    text TEXT NOT NULL,
                    text_hash VARCHAR(64) NOT NULL,
                    is_retweet TINYINT(1) DEFAULT 0,
                    is_quote TINYINT(1) DEFAULT 0,
                    original_author VARCHAR(128),
                    quoted_url VARCHAR(1024),
                    media_json TEXT,
                    x_created_at DATETIME,
                    fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    synced TINYINT(1) DEFAULT 0,
                    synced_at DATETIME,
                    synced_bsky_uri VARCHAR(512),
                    skipped TINYINT(1) DEFAULT 0,
                    skipped_at DATETIME,
                    UNIQUE KEY uk_x_post_id (x_post_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        } else {
            $pdo->exec('
                CREATE TABLE IF NOT EXISTS posts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    x_post_id TEXT NOT NULL UNIQUE,
                    x_post_url TEXT,
                    x_author TEXT,
                    text TEXT NOT NULL,
                    text_hash TEXT NOT NULL,
                    is_retweet INTEGER DEFAULT 0,
                    is_quote INTEGER DEFAULT 0,
                    original_author TEXT,
                    x_created_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS post_media (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    platform TEXT NOT NULL,
                    media_type TEXT NOT NULL DEFAULT "image",
                    original_url TEXT NOT NULL,
                    local_path TEXT,
                    alt_text TEXT,
                    width INTEGER,
                    height INTEGER
                )
            ');

            $pdo->exec('
                CREATE TABLE IF NOT EXISTS synced_destinations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    post_id INTEGER NOT NULL,
                    platform TEXT NOT NULL,
                    platform_post_url TEXT,
                    platform_post_uri TEXT,
                    synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    status TEXT DEFAULT "pending",
                    error_message TEXT
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
                CREATE TABLE IF NOT EXISTS fetched_posts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    x_post_id TEXT NOT NULL UNIQUE,
                    x_post_url TEXT,
                    text TEXT NOT NULL,
                    text_hash TEXT NOT NULL,
                    is_retweet INTEGER DEFAULT 0,
                    is_quote INTEGER DEFAULT 0,
                    original_author TEXT,
                    quoted_url TEXT,
                    media_json TEXT,
                    x_created_at DATETIME,
                    fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    synced INTEGER DEFAULT 0,
                    synced_at DATETIME,
                    synced_bsky_uri TEXT,
                    skipped INTEGER DEFAULT 0,
                    skipped_at DATETIME
                )
            ');
        }

        if ($type === 'mysql') {
            $indexCheck = function($pdo, $table, $indexName) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
                $stmt->execute([$table, $indexName]);
                return (int)$stmt->fetchColumn() > 0;
            };

            if (!$indexCheck($pdo, 'posts', 'idx_posts_x_created_at')) {
                $pdo->exec('CREATE INDEX idx_posts_x_created_at ON posts(x_created_at)');
            }
            if (!$indexCheck($pdo, 'posts', 'idx_posts_text_hash')) {
                $pdo->exec('CREATE INDEX idx_posts_text_hash ON posts(text_hash)');
            }
            if (!$indexCheck($pdo, 'post_media', 'idx_post_media_post_platform')) {
                $pdo->exec('CREATE INDEX idx_post_media_post_platform ON post_media(post_id, platform)');
            }
            if (!$indexCheck($pdo, 'synced_destinations', 'idx_synced_destinations_platform')) {
                $pdo->exec('CREATE INDEX idx_synced_destinations_platform ON synced_destinations(platform)');
            }
            if (!$indexCheck($pdo, 'synced_destinations', 'idx_synced_destinations_synced_at')) {
                $pdo->exec('CREATE INDEX idx_synced_destinations_synced_at ON synced_destinations(synced_at)');
            }
            if (!$indexCheck($pdo, 'logs', 'idx_logs_level')) {
                $pdo->exec('CREATE INDEX idx_logs_level ON logs(level)');
            }
            if (!$indexCheck($pdo, 'logs', 'idx_logs_created_at')) {
                $pdo->exec('CREATE INDEX idx_logs_created_at ON logs(created_at)');
            }
        } else {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_x_created_at ON posts(x_created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_posts_text_hash ON posts(text_hash)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_post_media_post_platform ON post_media(post_id, platform)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_synced_destinations_platform ON synced_destinations(platform)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_synced_destinations_synced_at ON synced_destinations(synced_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at)');
        }
    }

    public static function resetInstance(): void
    {
        self::$pdo = null;
    }
}