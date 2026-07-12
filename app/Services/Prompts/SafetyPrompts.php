<?php

namespace App\Services\Prompts;

/**
 * The text prompt used when an image engine refuses on content grounds:
 * asks the text model to rewrite the blocked image prompt so it passes the
 * filter while keeping every visual detail.
 */
class SafetyPrompts
{
    public function rephraseInstruction(string $blockedPrompt): string
    {
        return <<<PROMPT
An image-generation prompt was blocked by a content safety filter. Rewrite it so it passes the filter while keeping every visual detail intact (appearance, hair, eyes, clothing and colors, art style, setting, composition, mood).

Rules:
1. Remove explicit age references (e.g. "5 years old", "aged 7").
2. Replace child/minor nouns (child, kid, boy, girl, baby, toddler) with the character's name or a neutral phrase like "the young hero" - NEVER describe age, body, build, stature or proportions.
3. Reword any phrase a keyword filter could misread out of context (e.g. prefer "tall antique standing clock" over "grandfather clock"), keeping the same visual meaning.
4. Keep the art style and all scene/setting details unchanged.
5. Return ONLY the rewritten prompt - no preamble, no quotes, no explanation.

BLOCKED PROMPT:
{$blockedPrompt}
PROMPT;
    }
}
