<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\ImageReference;

/**
 * A provider whose configured model can generate several images as ONE
 * coherent set (same characters, same style across the set) - what Seedream
 * calls sequential image generation. The pipeline uses it to render all of
 * a book's pages in a single request instead of page-by-page rolls.
 */
interface SupportsImageGroups
{
    /**
     * Whether the CURRENTLY CONFIGURED model can produce grouped sets.
     */
    public function supportsImageGroups(): bool;

    /**
     * Generate up to $count images as one coherent set, in scene order.
     * May return fewer images than requested (the model decides); callers
     * fill the remainder through the per-image path.
     *
     * @param  list<ImageReference>  $references
     * @return list<string> raw image bytes, one entry per generated image
     */
    public function imageGroup(string $prompt, string $size, array $references, int $count): array;
}
