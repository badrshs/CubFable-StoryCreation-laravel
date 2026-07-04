<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\Character;
use App\Models\ImagePrompt;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateStorybookJobTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');

        Storage::fake('public');
        Http::preventStrayRequests();
    }

    public function test_generates_a_complete_storybook_with_cover_and_page_images(): void
    {
        $book = $this->pendingBookWithCast();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->storyChatResponse()),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->cover_image_path);
        Storage::disk('public')->assertExists($book->cover_image_path);
        $this->assertNotNull($book->hero_sheet_path);
        Storage::disk('public')->assertExists($book->hero_sheet_path);

        // Every generated image persists the prompt that produced it.
        $this->assertStringContainsString('FRONT COVER', (string) $book->cover_prompt);
        $this->assertStringContainsString('Character reference sheet', (string) $book->hero_sheet_prompt);

        // The hero is always drawn happy, on the cover and on every page.
        $this->assertStringContainsString('radiantly happy', (string) $book->cover_prompt);
        $this->assertStringContainsString('big happy smile', (string) $book->hero_sheet_prompt);

        // The book bible is persisted and drives the art direction.
        $this->assertSame('and the Glowing Lantern', $book->story_bible['subtitle']);
        $this->assertStringContainsString('crooked stone bridge', (string) $book->story_bible['world']);
        $this->assertSame('a tiny ladybug', $book->story_bible['motif']);
        $this->assertStringContainsString('and the Glowing Lantern', (string) $book->cover_prompt);

        // The cover is designed key art: the story's iconic moment, a themed
        // title treatment, and the find-it motif hidden on the cover too.
        $this->assertStringContainsString('COVER KEY ART', (string) $book->cover_prompt);
        $this->assertStringContainsString('leaps across the crooked stone bridge', (string) $book->cover_prompt);
        $this->assertStringContainsString('woven from glowing lantern light', (string) $book->cover_prompt);
        $this->assertStringContainsString('Hide a tiny ladybug', (string) $book->cover_prompt);

        // ... and every attempt is journaled (first attempts all succeed here).
        $journal = ImagePrompt::query()->where('book_id', $book->id)->get();
        $this->assertCount(5, $journal);
        $this->assertTrue($journal->every(fn (ImagePrompt $p): bool => $p->accepted && $p->attempt === 1 && $p->variant === 'original'));
        $this->assertSame(
            ['character-sheet' => 1, 'cover' => 1, 'page' => 3],
            $journal->countBy('purpose')->sortKeys()->all(),
        );
        $this->assertCount(3, $journal->where('purpose', 'page')->pluck('page_id')->filter()->unique());

        $pages = $book->pages()->get();
        $this->assertCount(3, $pages);

        foreach ($pages as $index => $page) {
            $this->assertSame($index + 1, $page->page_number);
            $this->assertSame(PageStatus::Complete, $page->status);
            $this->assertNotNull($page->image_path);
            Storage::disk('public')->assertExists($page->image_path);
            $this->assertStringContainsString('page '.($index + 1), (string) $page->image_prompt);
            $this->assertStringContainsString('MOOD IS CRITICAL', (string) $page->image_prompt);

            // The anchored hero is identified by the reference image alone;
            // no text description competes with it.
            $this->assertStringContainsString('reference image 1 (the character sheet)', (string) $page->image_prompt);
            $this->assertStringNotContainsString('Short curly brown hair', (string) $page->image_prompt);

            // Every page is art-directed from the bible: shot, stable world,
            // its own lighting note, and the find-it motif.
            $lightings = ['warm morning gold', 'bright silver noon', 'deep-blue starlight'];
            $this->assertNotNull($page->art_direction);
            $this->assertStringContainsString('SHOT:', (string) $page->image_prompt);
            $this->assertStringContainsString('crooked stone bridge', (string) $page->image_prompt);
            $this->assertStringContainsString('Lighting: '.$lightings[$index], (string) $page->image_prompt);
            $this->assertStringContainsString('FIND-IT MOTIF: hide a tiny ladybug', (string) $page->image_prompt);
        }

        $usage = AiUsage::query()->where('book_id', $book->id)->get();
        $this->assertGreaterThan(0, $usage->count());
        $this->assertSame(1, $usage->where('kind', 'text')->count());
        // Character sheet + cover + 3 pages.
        $this->assertSame(5, $usage->where('kind', 'image')->count());

        // The sheet itself is generated without references (no photo on the
        // cast), while the anchored cover and pages carry it as a reference
        // and therefore use the edits endpoint.
        Http::assertSentCount(6);
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'images/generations'))->count());
        $this->assertSame(4, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'images/edits'))->count());
    }

    public function test_image_failures_flip_pages_to_failed_but_the_book_completes(): void
    {
        $book = $this->pendingBookWithCast();

        // Portrait images (character sheet and cover, 1024x1536) succeed;
        // every page image (landscape 1536x1024) 500s.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response($this->storyChatResponse());
            }

            if ($this->requestField($request, 'size') === '1024x1536') {
                return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
            }

            return Http::response(['error' => ['message' => 'upstream exploded']], 500);
        });

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->cover_image_path);

        $pages = $book->pages()->get();
        $this->assertCount(3, $pages);

        foreach ($pages as $page) {
            $this->assertSame(PageStatus::Failed, $page->status);
            $this->assertNull($page->image_path);
        }
    }

    public function test_a_story_text_failure_flips_the_book_to_failed(): void
    {
        $book = $this->pendingBookWithCast();

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'upstream exploded']], 500),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Failed, $book->status);
        $this->assertSame(0, Page::query()->where('book_id', $book->id)->count());
    }

    public function test_page_regeneration_reuses_the_stored_hero_sheet(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);

        $book = Book::factory()->complete()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'theme' => 'forest',
        ]);

        $sheetPath = "books/{$book->id}/sheet-abcd1234.png";
        Storage::disk('public')->put($sheetPath, (string) base64_decode(self::PNG_BASE64, true));
        $book->update(['hero_sheet_path' => $sheetPath]);

        $character = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'role' => 'self',
            'appearance' => 'Short curly brown hair, green eyes, yellow raincoat, blue boots.',
        ]);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'scene' => 'Mia waves from the mossy path.',
            'status' => PageStatus::Generating,
        ]);

        // Only the anchored page call happens: no new sheet, no text calls.
        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        app(StoryGenerator::class)->regeneratePageIllustration($page, $book);

        $this->assertSame(PageStatus::Complete, $page->refresh()->status);
        $this->assertNotNull($page->image_path);
        Http::assertSentCount(1);
        $this->assertSame($sheetPath, $book->refresh()->hero_sheet_path);
    }

    public function test_an_interrupted_run_resumes_without_rewriting_or_rebilling(): void
    {
        $book = $this->pendingBookWithCast();

        // Simulate the state a dead worker leaves behind: story written,
        // pages created, two images done, one missing, no cover yet.
        $existingImage = 'books/'.$book->id.'/pages/1-existing.png';
        Storage::disk('public')->put($existingImage, (string) base64_decode(self::PNG_BASE64, true));
        $existingImageTwo = 'books/'.$book->id.'/pages/2-existing.png';
        Storage::disk('public')->put($existingImageTwo, (string) base64_decode(self::PNG_BASE64, true));

        Page::query()->create(['book_id' => $book->id, 'page_number' => 1, 'text' => 'Mia finds a lantern.', 'scene' => 'Mia holds a glowing lantern.', 'image_path' => $existingImage, 'status' => PageStatus::Complete]);
        Page::query()->create(['book_id' => $book->id, 'page_number' => 2, 'text' => 'Mia follows the light.', 'scene' => 'Mia walks a mossy path.', 'image_path' => $existingImageTwo, 'status' => PageStatus::Complete]);
        Page::query()->create(['book_id' => $book->id, 'page_number' => 3, 'text' => 'Mia lights the way home.', 'scene' => 'Mia stands on a hill.', 'image_path' => null, 'status' => PageStatus::Generating]);

        // No chat/completions fake on purpose: rewriting the story on resume
        // would hit preventStrayRequests and fail loudly.
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->cover_image_path);

        // The finished pages kept their files; only page 3 was generated.
        $this->assertSame($existingImage, $book->pages()->where('page_number', 1)->first()->image_path);
        $this->assertSame($existingImageTwo, $book->pages()->where('page_number', 2)->first()->image_path);
        $this->assertSame(PageStatus::Complete, $book->pages()->where('page_number', 3)->first()->status);
        $this->assertNotNull($book->pages()->where('page_number', 3)->first()->image_path);

        // Character sheet + cover + one missing page = exactly 3 image calls.
        Http::assertSentCount(3);
    }

    public function test_old_format_story_responses_still_work(): void
    {
        $book = $this->pendingBookWithCast();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->legacyStoryChatResponse()),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNull($book->story_bible);

        $page = $book->pages()->first();
        $this->assertNull($page->art_direction);
        $this->assertStringContainsString('Scene: Mia holds a glowing lantern', (string) $page->image_prompt);
        $this->assertStringNotContainsString('SHOT:', (string) $page->image_prompt);

        // The generic subtitle map still covers the cover, with the classic
        // hero-portrait composition instead of designed key art.
        $this->assertStringContainsString('and the Whispering Forest', (string) $book->cover_prompt);
        $this->assertStringContainsString('Below the title,', (string) $book->cover_prompt);
        $this->assertStringNotContainsString('COVER KEY ART', (string) $book->cover_prompt);
    }

    public function test_photo_mode_references_the_original_upload_and_skips_the_sheet(): void
    {
        config()->set('cubfable.ai.identity_reference', 'photo');

        $book = $this->pendingBookWithCast();

        // Give the hero a real stored photo.
        $hero = $book->characters()->first();
        Storage::disk('public')->put("characters/{$hero->id}/photo-test1234.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $hero->update(['photo_path' => "characters/{$hero->id}/photo-test1234.jpg"]);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->storyChatResponse()),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);

        // No character sheet in photo mode; the upload is the anchor.
        $this->assertNull($book->hero_sheet_path);
        $this->assertNull($book->hero_sheet_prompt);

        // Cover + 3 pages, every one on the edits endpoint (photo attached).
        $this->assertSame(4, ImagePrompt::query()->where('book_id', $book->id)->where('accepted', true)->count());
        $this->assertSame(4, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'images/edits'))->count());
        $this->assertSame(0, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'images/generations'))->count());

        // With the photo attached, the prompt points at the reference and
        // carries no competing text description of the hero.
        $pagePrompt = (string) $book->pages()->first()->image_prompt;
        $this->assertStringContainsString('attached reference image 1', $pagePrompt);
        $this->assertStringNotContainsString('Short curly brown hair', $pagePrompt);
    }

    public function test_photo_mode_still_builds_a_sheet_when_there_is_no_photo(): void
    {
        config()->set('cubfable.ai.identity_reference', 'photo');

        $book = $this->pendingBookWithCast();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->storyChatResponse()),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->hero_sheet_path);
    }

    public function test_the_job_bails_when_the_book_is_not_pending(): void
    {
        $book = $this->pendingBookWithCast();
        $book->update(['status' => BookStatus::Draft]);

        Http::fake();

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $this->assertSame(BookStatus::Draft, $book->refresh()->status);
        Http::assertNothingSent();
    }

    /**
     * A paid book in the Pending state with a main character whose appearance
     * is already cached (so generation makes no extra vision/text calls).
     */
    private function pendingBookWithCast(): Book
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 3]);

        $book = Book::factory()
            ->pending()
            ->for($user)
            ->for($template)
            ->create([
                'child_name' => 'Mia',
                'theme' => 'forest',
                'subject' => 'a glowing lantern',
                'language' => 'en',
            ]);

        $character = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'role' => 'self',
            'appearance' => 'Short curly brown hair, green eyes, yellow raincoat, blue boots.',
        ]);

        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        return $book;
    }

    /**
     * Read a named field from a faked request, whether it was sent as JSON
     * or as a multipart form (the edits endpoint used when references are
     * attached).
     */
    private function requestField(Request $request, string $field): ?string
    {
        $data = $request->data();

        if (isset($data[$field]) && is_string($data[$field])) {
            return $data[$field];
        }

        foreach ($data as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $field && is_string($part['contents'] ?? null)) {
                return $part['contents'];
            }
        }

        return null;
    }

    /**
     * A canned chat completion whose content is a valid 3-page book bible.
     *
     * @return array<string, mixed>
     */
    private function storyChatResponse(): array
    {
        $blueprint = [
            'subtitle' => 'and the Glowing Lantern',
            'world' => 'A mossy forest clearing crossed by a crooked stone bridge, lantern-lit oaks and a carpet of bluebells.',
            'motif' => 'a tiny ladybug',
            'refrain' => 'Sniff, sniff... something smells like adventure!',
            'colorScript' => ['warm morning gold', 'bright silver noon', 'deep-blue starlight'],
            'cover' => [
                'moment' => 'Mia leaps across the crooked stone bridge holding the lantern high as fireflies spiral around her.',
                'titleStyle' => 'hand-lettered letters woven from glowing lantern light and firefly trails',
            ],
            'pages' => [
                [
                    'text' => 'Mia finds a lantern.',
                    'scene' => [
                        'shot' => 'wide establishing',
                        'action' => 'Mia holds a glowing lantern at the edge of the forest.',
                        'expression' => 'curious',
                        'detail' => 'a woolly scarf trails behind her',
                    ],
                ],
                [
                    'text' => 'Mia follows the light.',
                    'scene' => [
                        'shot' => 'close-up',
                        'action' => 'Mia walks a mossy path lit by the lantern.',
                        'expression' => 'amazed',
                        'detail' => 'fireflies circle the lantern glass',
                    ],
                ],
                [
                    'text' => 'Mia lights the way home.',
                    'scene' => [
                        'shot' => "bird's eye",
                        'action' => 'Mia stands on a hill as the lantern glows over the trees.',
                        'expression' => 'joyful',
                        'detail' => 'the crooked bridge glows far below',
                    ],
                ],
            ],
        ];

        return [
            'choices' => [['message' => ['content' => json_encode($blueprint)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
        ];
    }

    /**
     * The legacy response shape: a plain array of {text, scene} strings.
     *
     * @return array<string, mixed>
     */
    private function legacyStoryChatResponse(): array
    {
        $story = [
            ['text' => 'Mia finds a lantern.', 'scene' => 'Mia holds a glowing lantern at the edge of the forest.'],
            ['text' => 'Mia follows the light.', 'scene' => 'Mia walks a mossy path lit by the lantern.'],
            ['text' => 'Mia lights the way home.', 'scene' => 'Mia stands on a hill as the lantern glows over the trees.'],
        ];

        return [
            'choices' => [['message' => ['content' => json_encode($story)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
        ];
    }
}
