<?php

namespace App\Services\AI\Contracts;

use App\Services\AI\ImageReference;

interface AiProvider
{
    /**
     * Generate free-form text from a prompt.
     */
    public function text(string $prompt, int $maxTokens): string;

    /**
     * Generate an image and return its raw binary bytes.
     *
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string;

    /**
     * Vision: describe a photo (base64 data URL) given an instruction.
     */
    public function describe(string $instruction, string $photoDataUrl): string;
}
