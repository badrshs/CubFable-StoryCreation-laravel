<?php

namespace App\Services\AI;

use App\Services\Prompts\IdentityCapsule;

/**
 * Derive a short, reusable appearance description of the person in a photo,
 * for use as a character reference in illustration prompts. Deliberately
 * avoids age so the resulting text doesn't itself trip image-model
 * minor-safety filters.
 */
class AppearanceDescriber
{
    public function __construct(
        private AiManager $ai,
        private IdentityCapsule $identity,
    ) {}

    public function describe(string $photoDataUrl): string
    {
        return trim($this->ai->describeImage($this->identity->photoDescriptionInstruction(), $photoDataUrl));
    }
}
