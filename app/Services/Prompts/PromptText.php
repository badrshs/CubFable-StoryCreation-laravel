<?php

namespace App\Services\Prompts;

/**
 * Tiny string helpers shared by the prompt composers and the blueprint
 * parser, so "clean one bible value" means the same thing everywhere.
 */
class PromptText
{
    public static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Clip text to a length at a word boundary, never mid-word.
     */
    public static function clip(string $text, int $maxLength): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t,;.");
    }

    /**
     * A single clean bible value: trimmed, unquoted, whitespace-collapsed,
     * length-capped, or null.
     */
    public static function line(mixed $value, int $maxLength): ?string
    {
        $line = trim(trim(self::stringify($value)), "\"'");
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        if ($line === '') {
            return null;
        }

        return mb_substr($line, 0, $maxLength);
    }
}
