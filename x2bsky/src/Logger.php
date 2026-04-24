<?php

declare(strict_types=1);

namespace X2BSky;

class Logger
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];
    private const LOG_LEVEL = 'info';

    public static function log(string $level, string $message, array $context = [], ?string $jobId = null): void
    {
        $minLevel = array_search(self::LOG_LEVEL, self::LEVELS);
        $msgLevel = array_search($level, self::LEVELS);

        if ($msgLevel < $minLevel) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        $logLine = sprintf(
            '[%s] [%s] %s %s %s',
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr ?: '',
            $jobId ? "[Job: $jobId]" : ''
        );

        error_log(trim($logLine));

        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare('INSERT INTO logs (job_id, level, message, context) VALUES (?, ?, ?, ?)');
            $stmt->execute([$jobId, $level, $message, $contextStr ?: null]);
        } catch (\Throwable $e) {
            error_log('Failed to write log to database: ' . $e->getMessage());
        }
    }

    public static function debug(string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('debug', $message, $context, $jobId);
    }

    public static function info(string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('info', $message, $context, $jobId);
    }

    public static function warning(string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('warning', $message, $context, $jobId);
    }

    public static function error(string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('error', $message, $context, $jobId);
    }

    public static function critical(string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('critical', $message, $context, $jobId);
    }
}
