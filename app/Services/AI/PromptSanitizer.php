<?php

namespace App\Services\AI;

/**
 * Deterministic, no-cost scrub of words that commonly trip image-model
 * minor-safety filters. Used as the first retry before falling back to an AI
 * rewrite. Keeps all visual detail; only neutralizes age/child terms.
 */
class PromptSanitizer
{
    /**
     * @var array<string, string>
     */
    private const REPLACEMENTS = [
        '/\b\d+[\s-]?year[s]?[\s-]?old\b/i' => 'young',
        '/\bage[d]?\s+\d+\b/i' => 'young',
        '/\bchildren\'?s\b/i' => 'storybook',
        '/\bchildren\b/i' => 'small people',
        '/\bchild\'?s\b/i' => "young character's",
        '/\bchild\b/i' => 'young character',
        '/\bkids\b/i' => 'small people',
        '/\bkid\b/i' => 'young character',
        '/\bboy\b/i' => 'young character',
        '/\bgirl\b/i' => 'young character',
        '/\btoddler\b/i' => 'small character',
        '/\binfant\b/i' => 'small character',
        '/\bbaby\b/i' => 'small character',
        '/\bminor\b/i' => 'young character',
    ];

    public function sanitize(string $prompt): string
    {
        $out = $prompt;

        foreach (self::REPLACEMENTS as $pattern => $replacement) {
            $out = (string) preg_replace($pattern, $replacement, $out);
        }

        return $out;
    }
}
