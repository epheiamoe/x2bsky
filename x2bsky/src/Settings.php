<?php

declare(strict_types=1);

namespace X2BSky;

class Settings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            if ($row) {
                $value = json_decode($row['value'], true);
                self::$cache[$key] = $value ?? $row['value'];
                return self::$cache[$key];
            }
        } catch (\Throwable $e) {
            Logger::error('Failed to get setting', ['key' => $key, 'error' => $e->getMessage()]);
        }

        return $default;
    }

    public static function set(string $key, mixed $value): bool
    {
        try {
            $pdo = Database::getInstance();
            $jsonValue = json_encode($value);

            $stmt = $pdo->prepare('
                INSERT INTO settings (key, value, updated_at)
                VALUES (?, ?, datetime("now"))
                ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
            ');
            $stmt->execute([$key, $jsonValue]);

            self::$cache[$key] = $value;
            return true;
        } catch (\Throwable $e) {
            Logger::error('Failed to set setting', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public static function getAll(): array
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->query('SELECT key, value FROM settings');
            $rows = $stmt->fetchAll();

            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['key']] = json_decode($row['value'], true) ?? $row['value'];
            }

            return $settings;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function delete(string $key): bool
    {
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('DELETE FROM settings WHERE key = ?');
            $stmt->execute([$key]);

            unset(self::$cache[$key]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function initDefaults(): void
    {
        $defaults = [
            'cron_enabled' => false,
            'cron_interval' => 5,
            'sync_count' => 10,
            'sync_include_rts' => false,
            'sync_include_quotes' => true,
            'fetch_default_count' => 20,
        ];

        foreach ($defaults as $key => $value) {
            if (self::get($key) === null) {
                self::set($key, $value);
            }
        }
    }
}
