<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\CharacterPortrait;
use App\Models\ImageVersion;
use App\Models\Template;
use App\Models\User;
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
