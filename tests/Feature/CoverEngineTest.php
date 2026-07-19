<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Jobs\RegenerateCoverJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\ImageVersion;
use App\Models\Template;
use App\Models\User;
use App\Services\BookStopSignal;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CoverEngineTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');
        config()->set('cubfable.ai.keys.replicate', 'test-token');
        config()->set('cubfable.ai.replicate_base_url', 'https://api.replicate.com');
        config()->set('cubfable.ai.identity_reference', 'photo');
        config()->set('cubfable.ai.cover_image_provider', 'replicate');
        config()->set('cubfable.ai.cover_image_model', 'bytedance/seedream-4.5');

        Storage::fake('public');
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function pendingBookWithPhotoHero(): Book
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 2]);

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
            'world' => 'A mossy forest clearing.',
            'colorScript' => ['warm morning gold', 'deep-blue starlight'],
            'cover' => ['moment' => 'Mia lifts the lantern high.', 'titleStyle' => 'glowing lantern letters'],
            'pages' => [
                ['text' => 'Mia finds a lantern.', 'scene' => ['shot' => 'wide establishing', 'action' => 'Mia holds a glowing lantern.', 'expression' => 'curious', 'detail' => 'a scarf trails']],
                ['text' => 'Mia lights the way home.', 'scene' => ['shot' => "bird's eye", 'action' => 'Mia stands on a hill.', 'expression' => 'joyful', 'detail' => 'the bridge glows below']],
            ],
        ];

        return [
            'choices' => [['message' => ['content' => json_encode($blueprint)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
        ];
    }

    public function test_the_cover_runs_on_its_dedicated_engine_while_pages_keep_the_main_one(): void
    {
        $book = $this->pendingBookWithPhotoHero();
        $png = (string) base64_decode(self::PNG_BASE64, true);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->storyChatResponse()),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.replicate.com/v1/files' => Http::response(['urls' => ['get' => 'https://api.replicate.com/v1/files/ref/download']]),
            'api.replicate.com/v1/models/bytedance/seedream-4.5/predictions' => Http::response([
                'id' => 'pred-cover',
                'status' => 'succeeded',
                'output' => ['https://replicate.delivery/cover.png'],
            ]),
            'replicate.delivery/*' => Http::response($png),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->cover_image_path);
        $this->assertSame(2, $book->pages()->where('status', PageStatus::Complete)->count());

        // The cover version is stamped with the dedicated engine; every page
        // version keeps the main engine.
        $this->assertSame(1, ImageVersion::query()->where('book_id', $book->id)->where('slot', 'cover')->where('engine_provider', 'replicate')->where('engine_model', 'bytedance/seedream-4.5')->count());
        $this->assertSame(2, ImageVersion::query()->where('book_id', $book->id)->where('slot', 'page')->where('engine_provider', 'openai')->count());

        // Exactly one Replicate prediction happened (the cover), built from
        // the catalog: the reference travels in Seedream's image_input array.
        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'api.replicate.com') && str_contains($request->url(), '/predictions'))->count());

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/predictions')) {
                return false;
            }

            $input = (array) $request->data()['input'];

            return $input['image_input'] === ['https://api.replicate.com/v1/files/ref/download']
                && str_contains((string) $input['prompt'], 'Front cover artwork');
        });
    }

    public function test_an_explicit_admin_override_beats_the_cover_engine(): void
    {
        $book = $this->pendingBookWithPhotoHero();
        $book->update(['status' => BookStatus::Complete]);

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new RegenerateCoverJob($book->id, 'openai', null))->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        $book->refresh();
        $this->assertNotNull($book->cover_image_path);
        $this->assertNull($book->cover_status);

        // The admin chose openai for this run: the configured Replicate
        // cover engine stays out of it entirely.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.replicate.com'));
        $this->assertSame(1, ImageVersion::query()->where('book_id', $book->id)->where('slot', 'cover')->where('engine_provider', 'openai')->count());
    }

    public function test_a_blank_cover_provider_keeps_the_cover_on_the_main_engine(): void
    {
        config()->set('cubfable.ai.cover_image_provider', '');

        $book = $this->pendingBookWithPhotoHero();
        $book->update(['status' => BookStatus::Complete]);

        Http::fake([
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new RegenerateCoverJob($book->id))->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.replicate.com'));
        $this->assertSame(1, ImageVersion::query()->where('book_id', $book->id)->where('slot', 'cover')->where('engine_provider', 'openai')->count());
    }
}
