<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\ImageVersion;
use App\Models\Template;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GroupGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');
        config()->set('cubfable.ai.image_provider', 'replicate');
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-4.5');
        config()->set('cubfable.ai.keys.replicate', 'test-token');
        config()->set('cubfable.ai.replicate_base_url', 'https://api.replicate.com');
        config()->set('cubfable.ai.group_generation', true);
        config()->set('cubfable.ai.identity_reference', 'photo');

        // Seedream's schema: image array input, ratio presets, and the
        // sequential-generation capability that unlocks group mode.
        Cache::put('replicate.input-schema.bytedance/seedream-4.5', [
            'prompt' => ['type' => 'string'],
            'image_input' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri']],
            'size' => ['default' => '2K'],
            'aspect_ratio' => ['default' => 'match_input_image'],
            'sequential_image_generation' => ['default' => 'disabled'],
            'max_images' => ['type' => 'integer'],
        ], now()->addHour());

        Storage::fake('public');
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function pendingBookWithPhotoHero(): Book
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 3]);

        $book = Book::factory()->pending()->for($user)->for($template)->create([
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
        Storage::disk('public')->put("characters/{$character->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $character->update(['photo_path' => "characters/{$character->id}/photo.jpg"]);

        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        return $book;
    }

    /**
     * @return array<string, mixed>
     */
    private function storyChatResponse(): array
    {
        $blueprint = [
            'subtitle' => 'and the Glowing Lantern',
            'world' => 'A mossy forest clearing crossed by a crooked stone bridge.',
            'motif' => 'a tiny ladybug',
            'refrain' => 'Sniff, sniff!',
            'colorScript' => ['warm morning gold', 'bright silver noon', 'deep-blue starlight'],
            'cover' => ['moment' => 'Mia leaps across the bridge.', 'titleStyle' => 'glowing lantern letters'],
            'pages' => [
                ['text' => 'Mia finds a lantern.', 'scene' => ['shot' => 'wide establishing', 'action' => 'Mia holds a glowing lantern.', 'expression' => 'curious', 'detail' => 'a scarf trails']],
                ['text' => 'Mia follows the light.', 'scene' => ['shot' => 'close-up', 'action' => 'Mia walks a mossy path.', 'expression' => 'amazed', 'detail' => 'fireflies circle']],
                ['text' => 'Mia lights the way home.', 'scene' => ['shot' => "bird's eye", 'action' => 'Mia stands on a hill.', 'expression' => 'joyful', 'detail' => 'the bridge glows below']],
            ],
        ];

        return [
            'choices' => [['message' => ['content' => json_encode($blueprint)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
        ];
    }

    public function test_all_pages_render_as_one_grouped_request(): void
    {
        $book = $this->pendingBookWithPhotoHero();
        $png = (string) base64_decode(self::PNG_BASE64, true);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->storyChatResponse()),
            'api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/ref/download']]),
            // First prediction: the cover (single). Second: the page group.
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::sequence()
                ->push(['id' => 'pred-cover', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/cover.png'])
                ->push(['id' => 'pred-group', 'status' => 'succeeded', 'output' => [
                    'https://replicate.delivery/p1.png',
                    'https://replicate.delivery/p2.png',
                    'https://replicate.delivery/p3.png',
                ]]),
            'replicate.delivery/*' => Http::response($png),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);

        foreach ($book->pages()->get() as $page) {
            $this->assertSame(PageStatus::Complete, $page->status);
            $this->assertNotNull($page->image_path);
            Storage::disk('public')->assertExists((string) $page->image_path);
            // Grouped pages share the one set prompt and are journaled as such.
            $this->assertStringContainsString('SCENE '.$page->page_number.':', (string) $page->image_prompt);
        }

        $this->assertDatabaseHas('image_prompts', ['book_id' => $book->id, 'variant' => 'group', 'accepted' => true]);
        $this->assertSame(3, ImageVersion::query()->where('book_id', $book->id)->where('slot', 'page')->where('engine_model', 'bytedance/seedream-4.5')->count());

        // The whole book took exactly TWO predictions: cover + page set.
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/predictions') && $request->method() === 'POST')->count());

        // The group request carried the sequential flags and the reference.
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return ($input['sequential_image_generation'] ?? null) === 'auto'
                && ($input['max_images'] ?? null) === 3
                && ($input['image_input'] ?? null) === ['https://api.replicate.com/v1/files/ref/download'];
        });
    }

    public function test_a_book_beyond_the_image_budget_renders_as_style_anchored_batches(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 15]);

        $book = Book::factory()->pending()->for($user)->for($template)->create([
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
        Storage::disk('public')->put("characters/{$character->id}/photo.jpg", (string) base64_decode(self::PNG_BASE64, true));
        $character->update(['photo_path' => "characters/{$character->id}/photo.jpg"]);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        $blueprint = [
            'world' => 'A mossy forest clearing.',
            'colorScript' => array_fill(0, 15, 'warm gold'),
            'pages' => array_map(fn (int $n): array => [
                'text' => "Mia explores, part {$n}.",
                'scene' => ['shot' => 'medium', 'action' => "Mia explores spot {$n}.", 'expression' => 'curious', 'detail' => "leaf {$n}"],
            ], range(1, 15)),
        ];

        $png = (string) base64_decode(self::PNG_BASE64, true);
        $groupUrls = fn (int $count, string $tag): array => array_map(fn (int $n): string => "https://replicate.delivery/{$tag}-{$n}.png", range(1, $count));

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($blueprint)]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
            'api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/ref/download']]),
            // Cover single, then batch 1 (8 pages), then batch 2 (7 pages).
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::sequence()
                ->push(['id' => 'pred-cover', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/cover.png'])
                ->push(['id' => 'pred-b1', 'status' => 'succeeded', 'output' => $groupUrls(8, 'b1')])
                ->push(['id' => 'pred-b2', 'status' => 'succeeded', 'output' => $groupUrls(7, 'b2')]),
            'replicate.delivery/*' => Http::response($png),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertSame(15, $book->pages()->where('status', PageStatus::Complete)->count());

        // Exactly three predictions: cover + two page batches - and the
        // second batch carried TWO references (the photo + the style anchor
        // from batch 1) with the style-match instruction in the prompt.
        $this->assertSame(3, Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/predictions') && $request->method() === 'POST')->count());

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return ($input['max_images'] ?? null) === 7
                && count((array) ($input['image_input'] ?? [])) === 2
                && str_contains((string) $input['prompt'], 'finished page of this same book');
        });
    }

    public function test_a_failed_group_falls_back_to_page_by_page(): void
    {
        $book = $this->pendingBookWithPhotoHero();
        $png = (string) base64_decode(self::PNG_BASE64, true);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->storyChatResponse()),
            'api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/ref/download']]),
            // Cover succeeds, the group fails, then three singles succeed.
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::sequence()
                ->push(['id' => 'pred-cover', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/cover.png'])
                ->push(['id' => 'pred-group', 'status' => 'failed', 'error' => 'GPU exploded'])
                ->push(['id' => 'pred-1', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/p1.png'])
                ->push(['id' => 'pred-2', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/p2.png'])
                ->push(['id' => 'pred-3', 'status' => 'succeeded', 'output' => 'https://replicate.delivery/p3.png']),
            'replicate.delivery/*' => Http::response($png),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertSame(3, $book->pages()->where('status', PageStatus::Complete)->count());
    }

    public function test_group_mode_stays_off_when_disabled_or_unsupported(): void
    {
        config()->set('cubfable.ai.group_generation', false);

        $this->assertFalse(app(AiManager::class)->supportsImageGroups() && (bool) config('cubfable.ai.group_generation'));

        // A kontext-style schema (no sequential generation) never groups.
        config()->set('cubfable.ai.group_generation', true);
        config()->set('cubfable.ai.models.image.replicate', 'black-forest-labs/flux-kontext-pro');
        Cache::put('replicate.input-schema.black-forest-labs/flux-kontext-pro', [
            'prompt' => ['type' => 'string'],
            'input_image' => ['type' => 'string'],
            'aspect_ratio' => ['type' => 'string'],
        ], now()->addHour());

        $this->assertFalse(app(AiManager::class)->supportsImageGroups());
    }
}
