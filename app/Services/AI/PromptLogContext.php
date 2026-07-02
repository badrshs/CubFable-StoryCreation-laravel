<?php

namespace App\Services\AI;

/**
 * Identifies which artifact an image generation belongs to, so every prompt
 * attempt can be journaled to the image_prompts table.
 */
final readonly class PromptLogContext
{
    public function __construct(
        public int $bookId,
        public string $purpose,
        public ?int $pageId = null,
    ) {}
}
