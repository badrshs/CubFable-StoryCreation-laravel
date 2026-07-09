<?php

namespace App\Services\Prompts;

/**
 * The single source of truth for story-writing craft: the author prompt
 * interpolates these exact rule blocks.
 */
class StoryCraftRules
{
    /**
     * Read-aloud craft calibrated per age band, injected into the writing
     * rules so a 2-4 book and an 8-10 book stop sounding the same.
     *
     * @var array<string, string>
     */
    private const AGE_WRITING_RULES = [
        '2-4' => '1-2 very short sentences per page, everyday words a toddler hears at home, lots of repetition and sound words (Boom! Splash!), name colors, animals and objects the child can point to in the picture.',
        '4-6' => '2-3 short sentences per page, playful sing-song rhythm, simple cause and effect, gentle humor, some sound words, at most one new word per page that the picture explains.',
        '6-8' => '3-4 sentences per page, richer vocabulary with context clues, a real small challenge and a clever solution, light humor and a dash of wordplay.',
        '8-10' => '3-5 sentences per page, vivid precise vocabulary, deeper feelings and a satisfying arc, wit welcome; never talk down to the reader.',
    ];

    public function ageRules(string $ageRange): string
    {
        return self::AGE_WRITING_RULES[$ageRange] ?? self::AGE_WRITING_RULES['4-6'];
    }

    /**
     * The numbered writing rules.
     */
    public function writingRules(string $heroName, string $ageRange, string $languageName): string
    {
        $ageRules = $this->ageRules($ageRange);
        $languageCraft = $this->languageCraft($languageName);

        return <<<RULES
WRITING RULES:
1. Age calibration for {$ageRange}: {$ageRules}
2. Shape the story in three beats across the pages: a warm setup that plants one clear problem; a middle where {$heroName} tries and stumbles at least twice, each attempt raising the stakes a little; then {$heroName}'s own idea, courage or kindness wins the day. Other characters help, they never rescue.
3. Weave the life lesson through choices and their consequences. NEVER state it as a moral; never write "learned that". Show feelings through what {$heroName} does, says and notices - not by naming the emotion.
4. Write for reading aloud: vary how sentences begin, keep a musical rhythm, and save the best sound word or small surprise for the last line of the page. End odd-numbered pages on a small question or surprise so the child wants to turn the page.
5. Give {$heroName} a short playful refrain in their own voice (a catchphrase or sound words) and repeat it on 2-3 pages, including near the end.
6. Page "text" is written in {$languageName}.{$languageCraft} Everything else (scene, world, subtitle, motif) is ENGLISH regardless of the story language.
RULES;
    }

    /**
     * Language-specific craft appended to the language rule. Arabic gets the
     * children's-book standard: simplified Modern Standard Arabic with full
     * diacritics, so the text reads aloud correctly for every age band.
     */
    private function languageCraft(string $languageName): string
    {
        if ($languageName === 'Arabic') {
            return ' Write simplified Modern Standard Arabic (فصحى مبسطة) with FULL diacritics (تشكيل) on every word, exactly like a printed children\'s picture book; never use dialect, slang or transliteration.';
        }

        if ($languageName !== 'English') {
            return ' Write native-quality prose in that language; never transliterate and never mix in English words.';
        }

        return '';
    }

    /**
     * The numbered art-direction rules.
     */
    public function artDirectionRules(string $heroName): string
    {
        return <<<RULES
ART DIRECTION RULES:
7. "world": 2-3 reusable sentences describing the main location(s) - architecture, colors, props, atmosphere. Every page happens in or near this world.
8. "colorScript": one short lighting/palette note per page (e.g. "warm morning gold"). Across the book the light should travel (e.g. morning to starry night) to mirror the emotional arc.
9. Every page "scene" object needs:
   - "shot": one of: wide establishing / medium / close-up / low angle / over-the-shoulder / bird's eye. Vary them - never the same shot twice in a row; open wide, go close at the emotional peak, pull back warm at the end.
   - "action": what visually happens - specific verbs, who stands where, what hands hold. Name every character present.
   - "expression": {$heroName}'s emotion, positive and fitting the moment (joyful, curious, focused, amazed, cozy, gently brave). Never sad, crying, scared or distressed.
   - "detail": one small memorable prop or micro-event unique to this page.
10. "motif": one tiny visual object (never a main character) hidden somewhere on every page for the child to find.
11. "subtitle": a charming English cover subtitle, at most 6 words.
12. "cover": design the front cover like a bestselling published picture book:
   - "moment": the single most magical, iconic moment of THIS story as cover key art - {$heroName} mid-action and full of wonder (never a static standing pose), with the story's world behind them and room at the top for the title.
   - "titleStyle": how the hand-lettered title should look, themed to the story (materials, colors, tiny ornaments - e.g. letters entwined with ivy, letters built from brass cogs and springs).
RULES;
    }

    /**
     * The exact JSON shape the author must return, on one line.
     */
    public function blueprintJsonShape(): string
    {
        return '{"subtitle":"...","world":"...","motif":"...","refrain":"...","colorScript":["one note per page"],"cover":{"moment":"...","titleStyle":"..."},"pages":[{"text":"...","scene":{"shot":"...","action":"...","expression":"...","detail":"..."}}]}';
    }
}
