<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Jobs\GenerateStorybookJob;
use App\Jobs\RegenerateCharacterPortraitJob;
use App\Jobs\RegeneratePageJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\CharacterPortrait;
use App\Models\ImagePrompt;
use App\Models\ImageVersion;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use App\Services\AI\ImageReference;
use App\Services\BookStopSignal;
use App\Services\Prompts\ImagePromptComposer;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CharacterPortraitTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');
        config()->set('cubfable.ai.identity_reference', 'sheet');

        Storage::fake('public');
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function makeCharacter(User $user): Character
    {
        return Character::factory()->for($user)->create([
            'name' => 'Mia',
            'role' => 'self',
            'appearance' => 'Short curly brown hair, green eyes, yellow raincoat.',
        ]);
    }

    private function pendingBook(User $user, Template $template, Character $character, string $style): Book
    {
        $book = Book::factory()->pending()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'theme' => 'forest',
            'subject' => 'a glowing lantern',
            'language' => 'en',
            'art_style' => $style,
        ]);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        return $book;
    }

    /**
     * @return array<string, mixed>
     */
    private function storyChatResponse(): array
    {
        $blueprint = [
            'world' => 'A mossy forest clearing.',
            'colorScript' => ['warm morning gold'],
            'pages' => [
                ['text' => 'Mia finds a lantern.', 'scene' => ['shot' => 'wide', 'action' => 'Mia holds a lantern.', 'expression' => 'curious', 'detail' => 'a scarf']],
            ],
        ];

        return [
            'choices' => [['message' => ['content' => json_encode($blueprint)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
        ];
    }

    private function fakeOpenAi(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response($this->storyChatResponse());
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });
    }

    private function imageCallCount(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'images/'))->count();
    }

    public function test_the_sheet_is_drawn_once_per_character_and_style_and_reused(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $character = $this->makeCharacter($user);
        $this->fakeOpenAi();

        $first = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($first->id))->handle(app(StoryGenerator::class));

        $this->assertSame(BookStatus::Complete, $first->refresh()->status);
        $portrait = CharacterPortrait::query()->sole();
        $this->assertSame($character->id, $portrait->character_id);
        $this->assertSame('watercolor', $portrait->art_style);
        $this->assertStringStartsWith("portraits/{$character->id}/watercolor-", $portrait->path);
        $this->assertSame($portrait->path, $first->hero_sheet_path);
        Storage::disk('public')->assertExists($portrait->path);

        // Sheet + cover + 1 page.
        $this->assertSame(3, $this->imageCallCount());

        $second = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($second->id))->handle(app(StoryGenerator::class));

        // The cached portrait anchored the second book: no new sheet was
        // generated (only cover + 1 page), no new portrait row exists.
        $this->assertSame(BookStatus::Complete, $second->refresh()->status);
        $this->assertSame($portrait->path, $second->hero_sheet_path);
        $this->assertSame(1, CharacterPortrait::query()->count());
        $this->assertSame(5, $this->imageCallCount());
    }

    public function test_supporting_characters_with_a_photo_also_get_a_cached_portrait(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $main = $this->makeCharacter($user);

        // A supporting character WITH a photo, named in the page scene so it
        // is present on the page.
        $sidekick = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'appearance' => 'A small round robot.',
        ]);
        // Distinct name so presence matching is unambiguous.
        $sidekick->update(['name' => 'Robo']);
        Storage::disk('public')->put("characters/{$sidekick->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $sidekick->update(['photo_path' => "characters/{$sidekick->id}/photo.jpg"]);

        $book = Book::factory()->pending()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'theme' => 'space',
            'subject' => 'a robot friend',
            'language' => 'en',
            'art_style' => 'watercolor',
        ]);
        $book->characters()->attach($main->id, ['is_main' => true, 'sort_order' => 0]);
        $book->characters()->attach($sidekick->id, ['is_main' => false, 'sort_order' => 1]);

        // Scene text names Robo so it is present on the page.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'chat/completions')) {
                $blueprint = [
                    'world' => 'Deep space.',
                    'colorScript' => ['starlight'],
                    'pages' => [
                        ['text' => 'Mia and Robo float together.', 'scene' => ['shot' => 'wide', 'action' => 'Mia and Robo float', 'expression' => 'joyful', 'detail' => 'stars']],
                    ],
                ];

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode($blueprint)]]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
                ]);
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $this->assertSame(BookStatus::Complete, $book->refresh()->status);

        // Both the hero and the photographed sidekick have a cached portrait.
        $this->assertSame(1, CharacterPortrait::query()->where('character_id', $main->id)->where('art_style', 'watercolor')->count());
        $this->assertSame(1, CharacterPortrait::query()->where('character_id', $sidekick->id)->where('art_style', 'watercolor')->count());
    }

    public function test_the_page_prompt_references_the_supporting_portrait_not_the_photo(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $main = $this->makeCharacter($user);

        $sidekick = Character::factory()->for($user)->create([
            'name' => 'Robo',
            'appearance' => 'A small round robot.',
        ]);
        Storage::disk('public')->put("characters/{$sidekick->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $sidekick->update(['photo_path' => "characters/{$sidekick->id}/photo.jpg"]);

        $portraitPath = "portraits/{$sidekick->id}/watercolor-abc.png";
        Storage::disk('public')->put($portraitPath, (string) base64_decode(self::PNG_BASE64, true));

        $book = Book::factory()->pending()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'theme' => 'space',
            'art_style' => 'watercolor',
        ]);
        $book->characters()->attach($main->id, ['is_main' => true, 'sort_order' => 0]);
        $book->characters()->attach($sidekick->id, ['is_main' => false, 'sort_order' => 1]);

        $page = Page::factory()->for($book)->create([
            'page_number' => 1,
            'text' => 'Mia and Robo float together.',
            'scene' => 'Mia and Robo float among the stars.',
        ]);

        $cast = $book->characters()->get();
        $castPortraits = [
            $sidekick->id => new ImageReference($portraitPath, 'Robo'),
        ];

        ['references' => $references] = app(ImagePromptComposer::class)
            ->page($book, $page, $cast, $main, null, $castPortraits);

        $paths = array_map(fn (ImageReference $r): string => $r->path, $references);

        // The sidekick's portrait is referenced; its raw photo is not.
        $this->assertContains($portraitPath, $paths);
        $this->assertNotContains("characters/{$sidekick->id}/photo.jpg", $paths);
    }

    public function test_a_different_style_gets_its_own_portrait(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $character = $this->makeCharacter($user);
        $this->fakeOpenAi();

        (new GenerateStorybookJob($this->pendingBook($user, $template, $character, 'watercolor')->id))->handle(app(StoryGenerator::class));
        (new GenerateStorybookJob($this->pendingBook($user, $template, $character, 'cartoon')->id))->handle(app(StoryGenerator::class));

        $styles = CharacterPortrait::query()->pluck('art_style')->sort()->values()->all();
        $this->assertSame(['cartoon', 'watercolor'], $styles);
    }

    public function test_the_portrait_engine_draws_the_sheet_while_pages_keep_the_main_engine(): void
    {
        config()->set('cubfable.ai.keys.replicate', 'test-token');
        config()->set('cubfable.ai.replicate_base_url', 'https://api.replicate.com');
        config()->set('cubfable.ai.portrait_image_provider', 'replicate');
        config()->set('cubfable.ai.portrait_image_model', 'bytedance/seedream-4.5');

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $character = $this->makeCharacter($user);
        $png = (string) base64_decode(self::PNG_BASE64, true);

        Http::fake(function (Request $request) use ($png) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response($this->storyChatResponse());
            }

            if (str_contains($request->url(), 'api.replicate.com')) {
                return Http::response([
                    'id' => 'pred-sheet',
                    'status' => 'succeeded',
                    'output' => ['https://replicate.delivery/sheet.png'],
                ]);
            }

            if (str_contains($request->url(), 'replicate.delivery')) {
                return Http::response($png);
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });

        $book = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $this->assertSame(BookStatus::Complete, $book->refresh()->status);

        // Exactly one Replicate prediction: the sheet. Cover and page stayed
        // on the main engine.
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.replicate.com') && str_contains($request->url(), '/predictions'))->count());
        $this->assertSame(2, $this->imageCallCount());

        $portrait = CharacterPortrait::query()->sole();
        $this->assertSame('replicate', $portrait->engine_provider);
        $this->assertSame('bytedance/seedream-4.5', $portrait->engine_model);
        $this->assertSame(1, ImageVersion::query()->where('slot', 'sheet')->where('engine_provider', 'replicate')->count());
    }

    public function test_replacing_the_photo_in_the_library_forgets_cached_portraits(): void
    {
        $user = User::factory()->create();
        $character = $this->makeCharacter($user);

        $path = "portraits/{$character->id}/watercolor-old.png";
        Storage::disk('public')->put($path, (string) base64_decode(self::PNG_BASE64, true));
        CharacterPortrait::query()->create([
            'character_id' => $character->id,
            'art_style' => 'watercolor',
            'path' => $path,
        ]);

        $this->actingAs($user)->patch(route('characters.update', ['id' => $character->id]), [
            'name' => 'Mia',
            'photoUrl' => 'data:image/png;base64,'.self::PNG_BASE64,
        ])->assertRedirect();

        // The cached reference is forgotten (next generation rebuilds it),
        // but the file is left alone: a book generated earlier may still use
        // it as its sheet.
        $this->assertSame(0, CharacterPortrait::query()->count());
        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_can_regenerate_the_character_portrait_without_touching_the_book(): void
    {
        config()->set('cubfable.ai.identity_reference', 'sheet');

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $character = $this->makeCharacter($user);
        $this->fakeOpenAi();

        // Generate the book once so it has a cover, a page, and a portrait.
        $book = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));
        $book->refresh();

        $originalCover = $book->cover_image_path;
        $originalSheet = $book->hero_sheet_path;
        $originalPage = $book->pages()->first()->image_path;
        $originalPortraitPath = CharacterPortrait::query()->sole()->path;

        // Regenerate only the character portrait.
        (new RegenerateCharacterPortraitJob($book->id))
            ->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        $book->refresh();

        // The character's portrait is replaced (shared across their books)...
        $portrait = CharacterPortrait::query()->sole();
        $this->assertNotSame($originalPortraitPath, $portrait->path);
        $this->assertSame($character->id, $portrait->character_id);

        // ...but the book is untouched: same cover, same page, same sheet
        // pointer, no extra page/cover work.
        $this->assertSame($originalCover, $book->cover_image_path);
        $this->assertSame($originalSheet, $book->hero_sheet_path);
        $this->assertSame($originalPage, $book->pages()->first()->image_path);
    }

    public function test_the_regenerated_portrait_is_shared_by_a_second_book_of_the_same_character(): void
    {
        config()->set('cubfable.ai.identity_reference', 'sheet');

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $character = $this->makeCharacter($user);
        $this->fakeOpenAi();

        $bookA = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($bookA->id))->handle(app(StoryGenerator::class));

        (new RegenerateCharacterPortraitJob($bookA->id))
            ->handle(app(StoryGenerator::class), app(BookStopSignal::class));
        $sharedPath = CharacterPortrait::query()->sole()->path;

        // A second book with the same character in the same style anchors to
        // the regenerated portrait (cache hit, no new portrait row).
        $bookB = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($bookB->id))->handle(app(StoryGenerator::class));

        $this->assertSame($sharedPath, $bookB->refresh()->hero_sheet_path);
        $this->assertSame(1, CharacterPortrait::query()->count());
    }

    public function test_manual_regeneration_uses_a_portrait_created_after_the_book_was_made(): void
    {
        // The reported case: a book first generated in photo mode (no sheet),
        // so hero_sheet_path is null. A portrait now exists for the character.
        // Regenerating a page must use that portrait, not the raw photo.
        config()->set('cubfable.ai.identity_reference', 'sheet');

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $main = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'appearance' => 'Short curly brown hair, yellow raincoat.',
        ]);
        Storage::disk('public')->put("characters/{$main->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $main->update(['photo_path' => "characters/{$main->id}/photo.jpg"]);

        $portraitPath = "portraits/{$main->id}/watercolor-new.png";
        Storage::disk('public')->put($portraitPath, (string) base64_decode(self::PNG_BASE64, true));
        CharacterPortrait::query()->create([
            'character_id' => $main->id,
            'art_style' => 'watercolor',
            'path' => $portraitPath,
        ]);

        $book = Book::factory()->complete()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'art_style' => 'watercolor',
            'hero_sheet_path' => null,
        ]);
        $book->characters()->attach($main->id, ['is_main' => true, 'sort_order' => 0]);

        $page = Page::factory()->for($book)->complete()->create([
            'page_number' => 1,
            'text' => 'Mia waves.',
            'scene' => 'Mia waves in the meadow.',
            'image_path' => "books/{$book->id}/pages/1-old.png",
        ]);
        Storage::disk('public')->put("books/{$book->id}/pages/1-old.png", (string) base64_decode(self::PNG_BASE64, true));

        // Spy the composer: capture the reference it is handed, then abort
        // before any real image call.
        $captured = null;
        $this->mock(ImagePromptComposer::class, function ($mock) use (&$captured): void {
            $mock->shouldReceive('page')
                ->andReturnUsing(function ($book, $page, $cast, $main, $anchor = null, $castPortraits = []) use (&$captured) {
                    $captured = ['anchor' => $anchor, 'portraits' => $castPortraits];
                    throw new \RuntimeException('stop-after-capture');
                });
        });

        (new RegeneratePageJob($page->id))
            ->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        $this->assertNotNull($captured, 'The composer was never called.');
        $this->assertInstanceOf(ImageReference::class, $captured['anchor']);
        $this->assertSame($portraitPath, $captured['anchor']->path);
        $this->assertSame($portraitPath, $captured['portraits'][$main->id]->path ?? null);
        // The raw photo is not used as the reference.
        $this->assertNotSame("characters/{$main->id}/photo.jpg", $captured['anchor']->path);
    }

    public function test_a_supporting_characters_portrait_can_be_regenerated_by_id(): void
    {
        config()->set('cubfable.ai.identity_reference', 'sheet');

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $main = $this->makeCharacter($user);
        $sidekick = Character::factory()->for($user)->create([
            'name' => 'Robo',
            'appearance' => 'A small round robot.',
        ]);
        Storage::disk('public')->put("characters/{$sidekick->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $sidekick->update(['photo_path' => "characters/{$sidekick->id}/photo.jpg"]);

        $book = Book::factory()->complete()->for($user)->for($template)->create(['art_style' => 'watercolor']);
        $book->characters()->attach($main->id, ['is_main' => true, 'sort_order' => 0]);
        $book->characters()->attach($sidekick->id, ['is_main' => false, 'sort_order' => 1]);

        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]])]);

        (new RegenerateCharacterPortraitJob($book->id, null, null, null, $sidekick->id))
            ->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        // Only the sidekick's portrait was created, not the hero's.
        $this->assertSame(1, CharacterPortrait::query()->where('character_id', $sidekick->id)->where('art_style', 'watercolor')->count());
        $this->assertSame(0, CharacterPortrait::query()->where('character_id', $main->id)->count());
    }

    public function test_a_character_from_another_book_cannot_be_targeted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);

        $book = Book::factory()->complete()->for($owner)->for($template)->create();
        $stranger = Character::factory()->for($owner)->create(['name' => 'Stranger']);

        $this->actingAs($admin)
            ->from("/admin/books/{$book->id}")
            ->post("/admin/books/{$book->id}/portrait/regenerate", ['characterId' => $stranger->id])
            ->assertSessionHasErrors('characterId');
    }

    public function test_manual_regeneration_generates_a_missing_portrait_instead_of_using_the_photo(): void
    {
        // Sheet mode, a photographed hero, but no portrait and no sheet yet.
        // Regenerating a page must draw the portrait (not fall back to photo).
        config()->set('cubfable.ai.identity_reference', 'sheet');

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $main = $this->makeCharacter($user);
        Storage::disk('public')->put("characters/{$main->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $main->update(['photo_path' => "characters/{$main->id}/photo.jpg"]);

        $book = Book::factory()->complete()->for($user)->for($template)->create([
            'art_style' => 'watercolor',
            'hero_sheet_path' => null,
        ]);
        $book->characters()->attach($main->id, ['is_main' => true, 'sort_order' => 0]);
        $page = Page::factory()->for($book)->complete()->create([
            'page_number' => 1,
            'text' => 'Mia waves.',
            'scene' => 'Mia waves.',
            'image_path' => "books/{$book->id}/pages/1-old.png",
        ]);
        Storage::disk('public')->put("books/{$book->id}/pages/1-old.png", (string) base64_decode(self::PNG_BASE64, true));

        $this->assertSame(0, CharacterPortrait::query()->where('character_id', $main->id)->count());

        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]])]);

        (new RegeneratePageJob($page->id))
            ->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        // The portrait was generated on the fly and used; the book's own sheet
        // pointer was NOT touched (the portrait belongs to the character).
        $portrait = CharacterPortrait::query()->where('character_id', $main->id)->where('art_style', 'watercolor')->first();
        $this->assertNotNull($portrait);
        $this->assertNull($book->refresh()->hero_sheet_path);

        $pagePrompt = ImagePrompt::query()->where('book_id', $book->id)->where('purpose', 'page')->where('accepted', true)->latest('id')->first();
        $paths = array_column($pagePrompt?->references ?? [], 'path');
        $this->assertContains($portrait->path, $paths);
        $this->assertNotContains("characters/{$main->id}/photo.jpg", $paths);
    }

    public function test_the_reference_images_used_are_recorded_on_each_attempt(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $character = $this->makeCharacter($user);
        $this->fakeOpenAi();

        $book = $this->pendingBook($user, $template, $character, 'watercolor');
        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        // The page attempt recorded the portrait it was anchored to, so the
        // reference behind any image is auditable from the database.
        $portrait = CharacterPortrait::query()->where('character_id', $character->id)->sole();
        $pagePrompt = ImagePrompt::query()
            ->where('book_id', $book->id)
            ->where('purpose', 'page')
            ->where('accepted', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($pagePrompt);
        $paths = array_column($pagePrompt->references ?? [], 'path');
        $this->assertContains($portrait->path, $paths);
        $this->assertNotContains("characters/{$character->id}/photo.jpg", $paths);
    }

    public function test_the_library_lists_portraits(): void
    {
        $user = User::factory()->create();
        $character = $this->makeCharacter($user);

        $path = "portraits/{$character->id}/watercolor-abc.png";
        Storage::disk('public')->put($path, (string) base64_decode(self::PNG_BASE64, true));
        CharacterPortrait::query()->create([
            'character_id' => $character->id,
            'art_style' => 'watercolor',
            'path' => $path,
        ]);

        $this->actingAs($user)
            ->get(route('characters.index'))
            ->assertInertia(fn ($page) => $page
                ->component('library')
                ->where('characters.0.portraits.0.artStyle', 'watercolor')
                ->whereNot('characters.0.portraits.0.url', null));
    }
}
