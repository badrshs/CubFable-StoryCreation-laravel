<?php

namespace Tests\Feature\Prompts;

use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Models\User;
use App\Services\Prompts\StoryCraftRules;
use App\Services\Prompts\StoryPromptComposer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoryCraftRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_age_rules_cover_every_band_and_fall_back_to_four_to_six(): void
    {
        $rules = new StoryCraftRules;

        foreach (['2-4', '4-6', '6-8', '8-10'] as $band) {
            $this->assertNotSame('', $rules->ageRules($band));
        }

        $this->assertSame($rules->ageRules('4-6'), $rules->ageRules('unknown-band'));
    }

    public function test_the_author_prompt_carries_every_craft_rule_block(): void
    {
        [$book, $cast, $main] = $this->bookWithCast();

        $rules = app(StoryCraftRules::class);
        $composer = app(StoryPromptComposer::class);

        $authorPrompt = $composer->authorPrompt($book, 3, $cast, $main);

        // The full rule blocks are interpolated verbatim: the concrete age
        // calibration, the numbered writing and art-direction rules, and
        // the blueprint JSON shape.
        foreach ([
            $rules->ageRules($book->age_range),
            $rules->writingRules('Mia', $book->age_range, 'English'),
            $rules->artDirectionRules('Mia'),
            $rules->blueprintJsonShape(),
        ] as $sharedBlock) {
            $this->assertStringContainsString($sharedBlock, $authorPrompt);
        }

        $this->assertStringContainsString('Write exactly 3 pages', $authorPrompt);
    }

    public function test_arabic_stories_demand_full_tashkeel(): void
    {
        [$book, $cast, $main] = $this->bookWithCast();
        $book->update(['language' => 'ar']);

        $composer = app(StoryPromptComposer::class);
        $prompt = $composer->authorPrompt($book->refresh(), 3, $cast, $main);

        $this->assertStringContainsString('Modern Standard Arabic', $prompt);
        $this->assertStringContainsString('تشكيل', $prompt);
        $this->assertStringContainsString('never use dialect', $prompt);

        // English books carry no language-craft clause at all.
        $book->update(['language' => 'en']);
        $this->assertStringNotContainsString('native-quality', $composer->authorPrompt($book->refresh(), 3, $cast, $main));
    }

    /**
     * @return array{0: Book, 1: Collection<int, Character>, 2: Character}
     */
    private function bookWithCast(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 3]);

        $book = Book::factory()->pending()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'age_range' => '6-8',
            'language' => 'en',
        ]);

        $character = Character::factory()->for($user)->create(['name' => 'Mia', 'role' => 'self']);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        $cast = $book->characters()->get();

        return [$book, $cast, $cast->first()];
    }
}
