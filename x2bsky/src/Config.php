<?php

declare(strict_types=1);

namespace X2BSky;

class Config
{
    private static ?array $config = null;
    private static ?string $configPath = null;

    public static function init(?string $path = null): void
    {
        self::$configPath = $path ?? dirname(__DIR__) . '/.env';
        self::load();
    }

    private static function load(): void
    {
        if (!file_exists(self::$configPath)) {
            throw new \RuntimeException('.env file not found at: ' . self::$configPath);
        }

        $lines = file(self::$configPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($value === 'true') {
                $value = true;
            } elseif ($value === 'false') {
                $value = false;
            }

            $config[$key] = $value;
        }

        self::$config = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$config === null) {
            self::init();
        }

        return self::$config[$key] ?? $default;
    }

    public static function all(): array
    {
        if (self::$config === null) {
            self::init();
        }

        return self::$config;
    }

    public static function getDSN(): string
    {
        $type = self::get('DB_TYPE', 'sqlite');
        if ($type === 'sqlite') {
            return 'sqlite:' . self::get('DB_PATH', '/data/x2bsky.db');
        }
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            self::get('DB_HOST', 'localhost'),
            self::get('DB_PORT', 5432),
            self::get('DB_NAME', 'x2bsky')
        );
    }
}
