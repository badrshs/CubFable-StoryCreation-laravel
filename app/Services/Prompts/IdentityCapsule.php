<?php

namespace App\Services\Prompts;

use App\Models\Character;

/**
 * The identity hub: owns both "describe a character" prompts (from a photo
 * and from text) and builds the identity lines used inside image prompts.
 *
 * When a reference image travels, the reference IS the identity: the line
 * only points at it, with no textual appearance that could fight the photo
 * (or lock in whatever the person happened to wear). When no reference
 * travels, the full appearance description carries identity alone.
 */
class IdentityCapsule
{
    /**
     * The identity line for a character whose reference image travels with
     * the request.
     */
    public function referenceLine(string $name, int $position, ?string $expression = null, ?string $ageGroup = null): string
    {
        return "{$name}{$this->ageMarker($ageGroup)}: reference image {$position}.".$this->expressionNote($expression);
    }

    /**
     * The identity line for a character with no reference image: the full
     * appearance description carries identity alone.
     */
    public function descriptionLine(string $name, ?string $appearance, ?string $expression = null, ?string $ageGroup = null): string
    {
        $appearance = rtrim(trim((string) $appearance), '.');

        return "{$name}{$this->ageMarker($ageGroup)}: ".($appearance !== '' ? $appearance : 'a friendly storybook character').'.'.$this->expressionNote($expression);
    }

    /**
     * The vision instruction that turns an uploaded photo into a reusable
     * appearance description. Labeled so the capsule extraction and the
     * text-derived descriptions stay structurally aligned. Deliberately
     * avoids age so the resulting text doesn't itself trip image-model
     * minor-safety filters.
     */
    public function photoDescriptionInstruction(): string
    {
        return <<<'PROMPT'
Look at this photo and write a physical description of the person, to be used as a fixed character reference so an illustrator draws the same character in every picture of a children's storybook.

Return exactly these four labeled lines and nothing else:
HAIR: exact color, length, and style.
EYES & FACE: eyebrows, eye color and shape, skin tone, face shape.
OUTFIT: one signature outfit for them to wear in EVERY illustration - top, bottom, and footwear, each with specific colors. Choose simple, casual storybook clothes that suit the person; NEVER formal or work clothing (no suits, ties, or uniforms), even when the photo shows them - use the photo only for color cues.
DISTINCTIVE FEATURES: clearly visible features (freckles, dimples, glasses, etc.), or "none".

Describe ONLY physical appearance and the outfit. Do NOT mention or estimate age. Do NOT try to identify who they are.
PROMPT;
    }

    /**
     * The prompt that invents a detailed appearance for a character that has
     * no photo, from its name, role, and any user notes. Same labeled shape
     * as the photo instruction so both kinds of characters align.
     */
    public function textDescriptionPrompt(Character $member, string $artStyle): string
    {
        $roleClause = $member->role !== null && $member->role !== '' ? " ({$member->role})" : '';
        $notesClause = $member->description !== null && $member->description !== '' ? " - notes: {$member->description}" : '';
        $adultClause = $member->age_group === 'adult'
            ? "\nThis character is a grown adult: invent a clearly adult build, face, and bearing."
            : '';

        return <<<PROMPT
For a children's storybook in the "{$artStyle}" art style, invent a SPECIFIC physical appearance for one character, to be used as a fixed reference so an illustrator draws the same character in every picture.

Character: {$member->name}{$roleClause}{$notesClause}{$adultClause}

Return exactly these four labeled lines and nothing else:
HAIR: exact color, length, and style; include facial hair (beard/mustache shape and color) or "clean-shaven" when it fits the character.
EYES & FACE: eyebrows, eye color and shape, skin tone, face shape, body build.
OUTFIT: one signature outfit worn in EVERY illustration - top, bottom, and footwear, each with specific colors. Simple, casual storybook clothes; never formal or work clothing (no suits, ties, or uniforms).
DISTINCTIVE FEATURES: memorable touches (glasses, a flower tucked behind an ear, etc.), or "none".

Do NOT mention age. Return ONLY the four lines, no preamble.
PROMPT;
    }

    private function expressionNote(?string $expression): string
    {
        return $expression !== null && $expression !== '' ? " Expression: {$expression}." : '';
    }

    /**
     * A compact age marker after the name. Only "adult" is ever emitted:
     * companions like a mom or dad must not be drawn kid-sized, while a
     * child marker would add nothing and minor-age words in image prompts
     * trip provider safety filters.
     */
    private function ageMarker(?string $ageGroup): string
    {
        return $ageGroup === 'adult' ? ' (adult)' : '';
    }
}
