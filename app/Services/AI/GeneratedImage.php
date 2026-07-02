<?php

namespace App\Services\AI;

/**
 * A successfully generated image together with the exact prompt that
 * produced it. When the safety-retry ladder rewrote the prompt, this is
 * the rewritten variant the provider actually accepted, not the original.
 */
final readonly class GeneratedImage
{
    public function __construct(
        public string $bytes,
        public string $prompt,
        public int $attempt,
    ) {}
}
