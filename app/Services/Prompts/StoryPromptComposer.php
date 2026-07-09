<?php

namespace App\Services\Prompts;

use App\Enums\StoryLanguage;
use App\Models\Book;
use App\Models\Character;
use Illuminate\Database\Eloquent\Collection;

/**
 * Composes the story-authoring prompt from the shared StoryCraftRules.
 */
class StoryPromptComposer
{
    public function __construct(private StoryCraftRules $rules) {}

    /**
     * One call, two jobs: the author writes the localized story and the art
     * director plans how every page looks (a reusable world, a lighting arc,
     * per-page shots, a hidden motif, a bespoke cover subtitle).
     *
     * @param  Collection<int, Character>  $cast
     */
    public function authorPrompt(Book $book, int $pageCount, Collection $cast, Character $main): string
    {
        $langName = $this->languageName($book);

        $others = $cast
            ->reject(fn (Character $character): bool => $character->id === $main->id)
            ->map(fn (Character $character): string => $character->name.($character->role !== null && $character->role !== '' ? " ({$character->role})" : ''))
            ->implode(', ');
        $othersText = $others !== '' ? $others : 'none';

        $writingRules = $this->rules->writingRules($main->name, $book->age_range, $langName);
        $artDirectionRules = $this->rules->artDirectionRules($main->name);
        $jsonShape = $this->rules->blueprintJsonShape();

        return <<<PROMPT
You are an award-winning children's picture-book author AND the book's art director. Create a complete book plan for a personalized storybook starring {$main->name} (age {$book->age_range}).

Story details:
- Setting / world: {$book->theme}
- What the story is about (the subject - make this central to the plot): {$book->subject}
- Life lesson: {$book->life_lesson}
- Art style: {$book->art_style}
- Additional characters: {$othersText}
- Story language: {$langName}

{$writingRules}

{$artDirectionRules}

Write exactly {$pageCount} pages. Return ONLY this JSON object (no other text):
{$jsonShape}
PROMPT;
    }

    private function languageName(Book $book): string
    {
        return StoryLanguage::tryFrom($book->language)?->label() ?? 'English';
    }
}
