<?php

declare(strict_types=1);

namespace X2BSky\Api;

class TextProcessor
{
    public static function splitForBluesky(string $text, int $maxChars = 300): array
    {
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
                if ($sentences === false || count($sentences) <= 1) {
                    $chunks = self::chunkByLength($paragraph, $maxChars);
                    foreach ($chunks as $chunk) {
                        $segments[] = $chunk;
                    }
                } else {
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

    public static function addThreadNotation(array $segments): array
    {
        $count = count($segments);
        if ($count <= 1) {
            return $segments;
        }

        foreach ($segments as $i => &$segment) {
            $segment['text'] .= sprintf(' (%d/%d)', $i + 1, $count);
        }

        return $segments;
    }

    public static function generateTextHash(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text));
        return md5($text);
    }

    private static function chunkByLength(string $text, int $maxChars): array
    {
        $chunks = [];
        $len = mb_strlen($text);
        $offset = 0;

        while ($offset < $len) {
            $chunk = mb_substr($text, $offset, $maxChars);
            $chunks[] = $chunk;
            $offset += $maxChars;
        }

        return $chunks;
    }
}
