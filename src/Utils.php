<?php

declare(strict_types=1);

namespace X2BSky;

class Utils
{
    public static function generateSecretKey(int $length = 32): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    public static function sanitizePath(string $path): string
    {
        $path = str_replace(['..', "\0"], '', $path);
        return realpath($path) ?: $path;
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public static function timeAgo(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff . 's ago';
        } elseif ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        } else {
            return floor($diff / 86400) . 'd ago';
        }
    }
}
