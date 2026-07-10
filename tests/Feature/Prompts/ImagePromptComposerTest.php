<?php

namespace Tests\Feature\Prompts;

use App\Models\Book;
use App\Models\Character;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use App\Services\AI\ImageReference;
use App\Services\Prompts\ImagePromptComposer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImagePromptComposerTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.image_provider', 'openai');
        Storage::fake('public');
    }

    public function test_page_prompt_uses_the_structured_template_in_order(): void
    {
        [$book, $cast, $main] = $this->bookWithCast();
        $page = $this->artDirectedPage($book);
        $anchor = $this->storedAnchor($book, $main);

        $prompt = $this->composer()->page($book, $page, $cast, $main, $anchor)['prompt'];

        $this->assertStringStartsWith('STYLE: ', $prompt);
        $this->assertSame(1, substr_count($prompt, 'STRICT CONSTRAINTS:'));

        $order = [
            'STYLE: ',
            'page 1',
            'SHOT: ',
            'Characters:',
            'STRICT CONSTRAINTS:',
            'No text, letters, numbers, watermarks or logos',
        ];
        $previous = -1;

        foreach ($order as $token) {
            $position = strpos($prompt, $token);
            $this->assertNotFalse($position, "missing '{$token}'");
            $this->assertGreaterThan($previous, $position, "'{$token}' out of order");
            $previous = $position;
        }
    }

    public function test_anti_drift_and_adaptation_appear_only_when_a_reference_travels(): void
    {
        [$book, $cast, $main] = $this->bookWithCast();
        $page = $this->artDirectedPage($book);
        $anchor = $this->storedAnchor($book, $main);

        $anchored = $this->composer()->page($book, $page, $cast, $main, $anchor)['prompt'];
        $this->assertStringContainsString('not flat 2D', $anchored);
        $this->assertStringContainsString('design that person as a brand-new soft 3D animated film character', $anchored);
        $this->assertStringContainsString('never photographic', $anchored);

        $unanchored = $this->composer()->page($book, $page, $cast, $main, null)['prompt'];
        $this->assertStringContainsString('not flat 2D', $unanchored);
        $this->assertStringNotContainsString('reference image', $unanchored);
    }

    public function test_budget_zero_prompts_never_mention_references(): void
    {
        config()->set('cubfable.ai.image_provider', 'flow');
        config()->set('cubfable.ai.models.image.flow', 'google-flow');

        [$book, $cast, $main] = $this->bookWithCast();
        $page = $this->artDirectedPage($book);
        $anchor = $this->storedAnchor($book, $main);

        // Even when an anchor is offered, a budget-0 engine drops it and the
        // prompt describes the hero in text instead.
        $result = $this->composer()->page($book, $page, $cast, $main, $anchor);

        $this->assertSame([], $result['references']);
        $this->assertStringContainsString('Mia: Short curly brown hair', $result['prompt']);
        $this->assertStringNotContainsString('reference image', $result['prompt']);
    }

    public function test_cover_keeps_the_title_block_and_its_own_constraints(): void
    {
        [$book, , $main] = $this->bookWithCast();

        $prompt = $this->composer()->cover($book, $main)['prompt'];

        $this->assertStringStartsWith('STYLE: ', $prompt);
        $this->assertStringContainsString('Front cover artwork for a children\'s picture book, 9:16 portrait.', $prompt);
        $this->assertStringContainsString('spelled exactly', $prompt);
        $this->assertStringContainsString('"Mia"', $prompt);
        $this->assertStringContainsString('No words or letters in the artwork beyond the two title lines above.', $prompt);

        // Margin/border language makes engines render a physical book mockup
        // on a background; the cover must demand full-bleed flat artwork.
        $this->assertStringNotContainsString('margin at the edges', $prompt);
        $this->assertStringContainsString('edge to edge', $prompt);
        $this->assertStringContainsString('never a photo or mockup of a physical book', $prompt);
    }

    public function test_sheet_prompt_frames_the_hero_alone(): void
    {
        [$book, , $main] = $this->bookWithCast();

        $prompt = $this->composer()->sheet($book, $main)['prompt'];

        $this->assertStringStartsWith('STYLE: ', $prompt);
        $this->assertStringContainsString('Character sheet', $prompt);
        $this->assertStringContainsString('friendly smile', $prompt);
        $this->assertStringContainsString('Only Mia in the frame, large and clear.', $prompt);
    }

    public function test_legacy_pages_keep_the_plain_scene_line(): void
    {
        [$book, $cast, $main] = $this->bookWithCast();
        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'scene' => 'Mia waves from the mossy path.',
            'art_direction' => null,
        ]);

        $prompt = $this->composer()->page($book, $page, $cast, $main)['prompt'];

        $this->assertStringContainsString('Scene: Mia waves from the mossy path.', $prompt);
        $this->assertStringNotContainsString('SHOT:', $prompt);
    }

    public function test_seedream_carries_every_cast_photo_as_a_reference(): void
    {
        config()->set('cubfable.ai.image_provider', 'replicate');
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');

        [$book, , $main] = $this->bookWithCast();

        $friend = Character::factory()->for($book->user)->create([
            'name' => 'Omar',
            'appearance' => 'Short black hair, red hoodie, gray sneakers.',
        ]);
        Storage::disk('public')->put("characters/{$friend->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $friend->update(['photo_path' => "characters/{$friend->id}/photo.jpg"]);
        $book->characters()->attach($friend->id, ['is_main' => false, 'sort_order' => 1]);
        $cast = $book->characters()->get();
        $anchor = $this->storedAnchor($book, $main);

        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'scene' => 'Mia and Omar cross the bridge.',
            'art_direction' => ['shot' => 'medium', 'action' => 'Mia and Omar cross the bridge.', 'expression' => 'joyful', 'detail' => 'a paper boat'],
        ]);

        $result = $this->composer()->page($book, $page, $cast, $main, $anchor);

        // Seedream's multi-image budget: the anchor AND the friend's photo
        // both travel, and the friend is named against his reference.
        $this->assertCount(2, $result['references']);
        $this->assertStringContainsString('Mia: reference image 1.', $result['prompt']);
        $this->assertStringContainsString('Omar: reference image 2.', $result['prompt']);
        $this->assertStringNotContainsString('Omar: Short black hair', $result['prompt']);
    }

    public function test_a_verbose_book_group_prompt_is_squeezed_under_the_engine_cap(): void
    {
        config()->set('cubfable.ai.image_provider', 'replicate');
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');

        [$book, $cast, $main] = $this->bookWithCast();
        $anchor = $this->storedAnchor($book, $main);

        // Seven pages of very long-winded art direction: far over the 4000
        // character budget at full length.
        $pages = [];

        foreach (range(1, 7) as $number) {
            $pages[] = Page::factory()->for($book)->create([
                'page_number' => $number,
                'scene' => "Scene {$number}",
                'art_direction' => [
                    'shot' => 'wide establishing',
                    'action' => str_repeat("Mia wanders slowly through the endless luminous forest of scene {$number}, pausing at every mossy stone and glowing flower along the winding path. ", 4),
                    'expression' => 'curious',
                    'detail' => 'a woolly scarf trails behind her through the ferns and glowing mushrooms',
                ],
            ]);
        }

        $result = $this->composer()->pageGroup($book, $pages, $cast, $main, $anchor);

        $this->assertLessThanOrEqual(3800, mb_strlen($result['prompt']));

        foreach (range(1, 7) as $number) {
            $this->assertStringContainsString("SCENE {$number}:", $result['prompt']);
        }

        // The shared blocks appear exactly once.
        $this->assertSame(1, substr_count($result['prompt'], 'STYLE: '));
        $this->assertSame(1, substr_count($result['prompt'], 'STRICT CONSTRAINTS:'));
    }

    public function test_secondary_character_beyond_the_budget_is_described_in_text(): void
    {
        config()->set('cubfable.ai.image_provider', 'flow');
        config()->set('cubfable.ai.models.image.flow', 'grok-imagine');

        [$book, $cast, $main] = $this->bookWithCast();

        $user = $book->user;
        $friend = Character::factory()->for($user)->create([
            'name' => 'Omar',
            'appearance' => 'Short black hair, red hoodie, gray sneakers.',
        ]);
        Storage::disk('public')->put("characters/{$friend->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $friend->update(['photo_path' => "characters/{$friend->id}/photo.jpg"]);
        $book->characters()->attach($friend->id, ['is_main' => false, 'sort_order' => 1]);
        $cast = $book->characters()->get();
        $anchor = $this->storedAnchor($book, $main);

        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'scene' => 'Mia and Omar cross the bridge.',
            'art_direction' => ['shot' => 'medium', 'action' => 'Mia and Omar cross the bridge.', 'expression' => 'joyful', 'detail' => 'a paper boat'],
        ]);

        $result = $this->composer()->page($book, $page, $cast, $main, $anchor);

        // Grok flow carries exactly one reference: the anchor. The friend's
        // photo stays home and his text description fills in.
        $this->assertCount(1, $result['references']);
        $this->assertStringContainsString('Mia: reference image 1.', $result['prompt']);
        $this->assertStringContainsString('Omar: Short black hair, red hoodie, gray sneakers.', $result['prompt']);
    }

    public function test_adult_companions_are_marked_in_the_character_lines(): void
    {
        config()->set('cubfable.ai.image_provider', 'replicate');
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');

        [$book, , $main] = $this->bookWithCast();

        $dad = Character::factory()->for($book->user)->create([
            'name' => 'Omar',
            'role' => 'dad',
            'age_group' => 'adult',
            'appearance' => 'Short black hair, a green flannel shirt.',
        ]);
        Storage::disk('public')->put("characters/{$dad->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $dad->update(['photo_path' => "characters/{$dad->id}/photo.jpg"]);
        $book->characters()->attach($dad->id, ['is_main' => false, 'sort_order' => 1]);
        $cast = $book->characters()->get();
        $anchor = $this->storedAnchor($book, $main);

        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'scene' => 'Mia and Omar cross the bridge.',
            'art_direction' => ['shot' => 'medium', 'action' => 'Mia and Omar cross the bridge.', 'expression' => 'joyful', 'detail' => 'a paper boat'],
        ]);

        $prompt = $this->composer()->page($book, $page, $cast, $main, $anchor)['prompt'];

        // Dad reads as an adult next to the hero; the hero (no age group)
        // carries no age wording at all - minor-age words trip safety filters.
        $this->assertStringContainsString('Omar (adult): reference image 2.', $prompt);
        $this->assertStringContainsString('Mia: reference image 1.', $prompt);
        $this->assertStringNotContainsString('Mia (adult)', $prompt);
    }

    private function composer(): ImagePromptComposer
    {
        return app(ImagePromptComposer::class);
    }

    /**
     * @return array{0: Book, 1: Collection<int, Character>, 2: Character}
     */
    private function bookWithCast(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 3]);

        $book = Book::factory()->complete()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'theme' => 'forest',
            'art_style' => '3d-animation',
            'story_bible' => [
                'subtitle' => 'and the Glowing Lantern',
                'world' => 'A mossy forest clearing crossed by a crooked stone bridge.',
                'motif' => 'a tiny ladybug',
                'colorScript' => ['warm morning gold', 'bright silver noon', 'deep-blue starlight'],
                'cover' => ['moment' => 'Mia leaps across the bridge.', 'titleStyle' => 'letters woven from lantern light'],
            ],
        ]);

        $character = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'role' => 'self',
            'appearance' => 'Short curly brown hair, green eyes, yellow raincoat, blue boots.',
        ]);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        $cast = $book->characters()->get();

        return [$book, $cast, $cast->first()];
    }

    private function artDirectedPage(Book $book): Page
    {
        return Page::factory()->for($book)->create([
            'page_number' => 1,
            'scene' => 'Mia holds a glowing lantern at the edge of the forest.',
            'art_direction' => [
                'shot' => 'wide establishing',
                'action' => 'Mia holds a glowing lantern at the edge of the forest.',
                'expression' => 'curious',
                'detail' => 'a woolly scarf trails behind her',
            ],
        ]);
    }

    private function storedAnchor(Book $book, Character $main): ImageReference
    {
        $path = "books/{$book->id}/sheet-test1234.png";
        Storage::disk('public')->put($path, (string) base64_decode(self::PNG_BASE64, true));

        return new ImageReference($path, "{$main->name} (character sheet)");
    }
}
