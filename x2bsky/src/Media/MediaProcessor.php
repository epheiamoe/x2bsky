<?php

declare(strict_types=1);

namespace X2BSky\Media;

use X2BSky\Config;
use X2BSky\Logger;

class MediaProcessor
{
    private const MAX_IMAGE_SIZE = 2097152;
    private const MAX_IMAGE_PIXELS = 4000000;
    private const MAX_VIDEO_SIZE = 104857600;
    private const MAX_VIDEO_DURATION = 180;
    private const MAX_IMAGES = 4;

    public static function processMedia(array $mediaItems, string $xPostId): array
    {
        $processed = [];
        $imageCount = 0;

        foreach ($mediaItems as $media) {
            if ($imageCount >= self::MAX_IMAGES) {
                Logger::warning('Max images reached, skipping', ['post_id' => $xPostId]);
                break;
            }

            $type = $media['type'] ?? 'unknown';
            $url = $media['url'] ?? $media['preview_image_url'] ?? '';

            if (!$url) {
                continue;
            }

            $extension = self::getExtensionFromUrl($url);
            $localPath = self::downloadToTemp($url, $extension);

            if (!$localPath) {
                Logger::warning('Failed to download media', ['url' => $url]);
                continue;
            }

            $result = ['original_path' => $localPath, 'type' => $type];

            if (self::isImage($type)) {
                $result = self::processImage($localPath, $media, $xPostId);
                $imageCount++;
            } elseif (self::isVideo($type)) {
                $result = self::processVideo($localPath, $media, $xPostId);
            }

            $processed[] = $result;
        }

        return $processed;
    }

    private static function downloadToTemp(string $url, string $extension): ?string
    {
        $tempDir = sys_get_temp_dir() . '/x2bsky_' . getmypid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . '/' . bin2hex(random_bytes(8)) . '.' . $extension;

        $ch = curl_init($url);
        $fp = fopen($tempFile, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
        ]);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode !== 200 || !file_exists($tempFile)) {
            @unlink($tempFile);
            return null;
        }

        return $tempFile;
    }

    private static function getMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'webm' => 'video/webm',
        ];

        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }

    private static function processImage(string $path, array $media, string $xPostId): array
    {
        $result = ['original_path' => $path, 'type' => 'image', 'blobs' => []];

        $originalSize = filesize($path);
        $needsCompression = $originalSize > self::MAX_IMAGE_SIZE;

        if ($needsCompression) {
            $path = self::compressImage($path);
        }

        $size = @getimagesize($path);
        if ($size) {
            $pixels = $size[0] * $size[1];
            if ($pixels > self::MAX_IMAGE_PIXELS) {
                $path = self::resizeImage($path, $size[0], $size[1]);
            }
        }

        $mime = self::getMimeType($path);
        $blob = self::uploadBlob($path, $mime);
        if ($blob) {
            $result['blobs'][] = [
                'blob' => $blob,
                'alt' => $media['alt_text'] ?? '',
            ];
        }

        return $result;
    }

    private static function compressImage(string $path): string
    {
        $size = filesize($path);
        $quality = 85;

        while ($size > self::MAX_IMAGE_SIZE && $quality > 20) {
            $info = getimagesize($path);
            $newWidth = (int) ($info[0] * 0.8);
            $newHeight = (int) ($info[1] * 0.8);

            $src = null;
            switch ($info['mime']) {
                case 'image/jpeg':
                    $src = imagecreatefromjpeg($path);
                    break;
                case 'image/png':
                    $src = imagecreatefrompng($path);
                    break;
                case 'image/gif':
                    $src = imagecreatefromgif($path);
                    break;
                case 'image/webp':
                    $src = imagecreatefromwebp($path);
                    break;
            }

            if (!$src) {
                return $path;
            }

            $dst = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $info[0], $info[1]);

            $newPath = $path . '.compressed.jpg';
            imagejpeg($dst, $newPath, $quality);
            imagedestroy($src);
            imagedestroy($dst);

            $size = filesize($newPath);
            if ($size < filesize($path)) {
                @unlink($path);
                $path = $newPath;
            } else {
                @unlink($newPath);
                break;
            }

            $quality -= 10;
        }

        return $path;
    }

    private static function resizeImage(string $path, int $width, int $height): string
    {
        $targetPixels = self::MAX_IMAGE_PIXELS;
        $currentPixels = $width * $height;
        $scale = sqrt($targetPixels / $currentPixels);

        $newWidth = (int) ($width * $scale);
        $newHeight = (int) ($height * $scale);

        $info = getimagesize($path);
        $src = null;
        switch ($info['mime']) {
            case 'image/jpeg':
                $src = imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $src = imagecreatefrompng($path);
                break;
            case 'image/webp':
                $src = imagecreatefromwebp($path);
                break;
        }

        if (!$src) {
            return $path;
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $newPath = $path . '.resized.jpg';
        imagejpeg($dst, $newPath, 90);
        imagedestroy($src);
        imagedestroy($dst);

        @unlink($path);
        return $newPath;
    }

    private static function processVideo(string $path, array $media, string $xPostId): array
    {
        $result = ['original_path' => $path, 'type' => 'video', 'blobs' => []];

        $size = filesize($path);
        $duration = self::getVideoDuration($path);

        $needsCompression = $size > self::MAX_VIDEO_SIZE || $duration > self::MAX_VIDEO_DURATION;

        if ($needsCompression) {
            $path = self::compressVideo($path);
        }

        $mime = self::getMimeType($path);
        $blob = self::uploadBlob($path, $mime);
        if ($blob) {
            $result['blobs'][] = [
                'blob' => $blob,
                'alt' => $media['alt_text'] ?? 'Video',
            ];
        }

        return $result;
    }

    private static function compressVideo(string $path): string
    {
        $newPath = $path . '.compressed.mp4';

        $duration = self::getVideoDuration($path);
        $vf = 'scale=1920:-1';

        if ($duration > self::MAX_VIDEO_DURATION) {
            $vf .= ',trim=duration=' . self::MAX_VIDEO_DURATION;
        }

        $cmd = sprintf(
            'ffmpeg -y -i %s -vf "%s" -c:v libx264 -crf 28 -c:a aac -b:a 128k %s 2>&1',
            escapeshellarg($path),
            $vf,
            escapeshellarg($newPath)
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && file_exists($newPath) && filesize($newPath) < filesize($path)) {
            @unlink($path);
            return $newPath;
        }

        @unlink($newPath);
        return $path;
    }

    private static function getVideoDuration(string $path): int
    {
        $cmd = sprintf('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null', escapeshellarg($path));
        $duration = exec($cmd);
        return (int) ((float) $duration);
    }

    private static function uploadBlob(string $path, string $mime): ?array
    {
        $fileData = file_get_contents($path);
        $base64Data = base64_encode($fileData);

        return [
            '$type' => 'blob',
            'mimeType' => $mime,
            'data' => $base64Data,
        ];
    }

    private static function isImage(string $type): bool
    {
        return in_array($type, ['photo', 'image']);
    }

    private static function isVideo(string $type): bool
    {
        return in_array($type, ['video', 'animated_gif']);
    }

    private static function getExtensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        return $ext ?: 'bin';
    }

    public static function cleanup(string $tempDir): void
    {
        if (is_dir($tempDir)) {
            array_map('unlink', glob("$tempDir/*"));
            rmdir($tempDir);
        }
    }
}
