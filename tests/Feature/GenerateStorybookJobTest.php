<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\Character;
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
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->cover_image_path);
        Storage::disk('public')->assertExists($book->cover_image_path);

        $pages = $book->pages()->get();
        $this->assertCount(3, $pages);

        foreach ($pages as $index => $page) {
            $this->assertSame($index + 1, $page->page_number);
            $this->assertSame(PageStatus::Complete, $page->status);
            $this->assertNotNull($page->image_path);
            Storage::disk('public')->assertExists($page->image_path);
        }

        $usage = AiUsage::query()->where('book_id', $book->id)->get();
        $this->assertGreaterThan(0, $usage->count());
        $this->assertSame(1, $usage->where('kind', 'text')->count());
        $this->assertSame(4, $usage->where('kind', 'image')->count());
    }

    public function test_image_failures_flip_pages_to_failed_but_the_book_completes(): void
    {
        $book = $this->pendingBookWithCast();

        // The cover (portrait 1024x1536) succeeds; every page image (landscape) 500s.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response($this->storyChatResponse());
            }

            if (($request->data()['size'] ?? null) === '1024x1536') {
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
     * A canned chat completion whose content is a valid 3-page story array.
     *
     * @return array<string, mixed>
     */
    private function storyChatResponse(): array
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
