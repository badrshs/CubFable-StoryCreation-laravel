<?php

namespace App\Services\AI;

/**
 * Derive a short, reusable appearance description of the person in a photo,
 * for use as a character reference in illustration prompts. Deliberately
 * avoids age so the resulting text doesn't itself trip image-model
 * minor-safety filters.
 */
class AppearanceDescriber
{
    public function __construct(private AiManager $ai) {}

    public function describe(string $photoDataUrl): string
    {
        $instruction = <<<'PROMPT'
Look at this photo and write a DETAILED, SPECIFIC physical description of the person, to be used as a fixed character reference so an illustrator can draw them IDENTICALLY in every picture of a children's storybook.

Include ALL of:
- hair: exact color, length, and style
- eyebrows, eye color and shape, skin tone, face shape
- any clearly visible distinctive features (freckles, dimples, glasses, etc.)
- a single signature outfit for them to wear in EVERY illustration: top, bottom, and footwear, each with specific colors.

Describe ONLY physical appearance and the outfit. Do NOT mention or estimate age. Do NOT try to identify who they are. Return ONLY the description text.
PROMPT;

        return trim($this->ai->describeImage($instruction, $photoDataUrl));
    }
}
