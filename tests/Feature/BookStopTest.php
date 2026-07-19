<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Jobs\RegenerateCoverJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Models\User;
use App\Services\BookStopSignal;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class BookStopTest extends TestCase
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
        Sleep::fake();
    }

    /**
     * @return array{0: User, 1: Book}
     */
    private function pendingBookWithCast(): array
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
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        return [$user, $book];
    }

    /**
     * @return array<string, mixed>
     */
    private function storyChatResponse(): array
    {
        $blueprint = [
            'world' => 'A mossy forest clearing.',
            'colorScript' => ['warm morning gold', 'deep-blue starlight'],
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

    public function test_a_stop_request_halts_the_pipeline_before_the_next_image_and_resume_continues(): void
    {
        [, $book] = $this->pendingBookWithCast();

        // The admin presses Stop while the story text is being written: the
        // fake sets the signal exactly when the blueprint call happens.
        Http::fake(function (Request $request) use ($book) {
            if (str_contains($request->url(), 'chat/completions')) {
                app(BookStopSignal::class)->request($book->id);

                return Http::response($this->storyChatResponse());
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();

        // The run halted before generating ANY image, but the story text
        // survived and the book is resumable, not stuck.
        $this->assertSame(BookStatus::Failed, $book->status);
        $this->assertNull($book->cover_image_path);
        $this->assertNull($book->hero_sheet_path);
        $this->assertSame(2, $book->pages()->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'images/'));

        // A new run (what Resume dispatches after flipping the book back to
        // Pending) clears the stale signal at its start and completes the
        // book: no chat call repeats (the story is kept), so the signal is
        // never re-set.
        $book->update(['status' => BookStatus::Pending]);
        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        $book->refresh();
        $this->assertSame(BookStatus::Complete, $book->status);
        $this->assertNotNull($book->cover_image_path);
        $this->assertSame(2, $book->pages()->where('status', PageStatus::Complete)->count());
    }

    public function test_an_honored_stop_clears_the_signal_so_the_next_run_is_not_killed(): void
    {
        [, $book] = $this->pendingBookWithCast();

        Http::fake(function (Request $request) use ($book) {
            if (str_contains($request->url(), 'chat/completions')) {
                app(BookStopSignal::class)->request($book->id);

                return Http::response($this->storyChatResponse());
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        // The stop served its purpose; a lingering flag (1 hour TTL) would
        // silently kill any regeneration queued within the hour.
        $this->assertFalse(app(BookStopSignal::class)->requested($book->id));
    }

    public function test_an_aborting_run_does_not_clobber_a_book_requeued_by_restyle(): void
    {
        [, $book] = $this->pendingBookWithCast();

        // Mid-run, the admin restyles: the book flips back to Pending and a
        // new job is queued while the old pipeline is signalled to stop.
        Http::fake(function (Request $request) use ($book) {
            if (str_contains($request->url(), 'chat/completions')) {
                app(BookStopSignal::class)->request($book->id);
                Book::query()->whereKey($book->id)->update(['status' => BookStatus::Pending]);

                return Http::response($this->storyChatResponse());
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        // The old run must not overwrite Pending with Failed, or the queued
        // restyle job refuses to start and the book is stuck.
        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
    }

    public function test_a_stale_stop_flag_does_not_kill_a_queued_regeneration(): void
    {
        [, $book] = $this->pendingBookWithCast();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'chat/completions')) {
                return Http::response($this->storyChatResponse());
            }

            return Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]);
        });

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));
        $this->assertSame(BookStatus::Complete, $book->refresh()->status);

        // The admin pressed Stop and the flag lingers (1 hour TTL). Queuing a
        // regeneration afterwards is an intentional act; the stale flag must
        // not kill it.
        app(BookStopSignal::class)->request($book->id);

        (new RegenerateCoverJob($book->id))->handle(app(StoryGenerator::class), app(BookStopSignal::class));

        $this->assertFalse(app(BookStopSignal::class)->requested($book->id));
        $this->assertNotSame('failed', $book->refresh()->cover_status);
    }

    public function test_the_admin_stop_endpoint_sets_the_signal(): void
    {
        [, $book] = $this->pendingBookWithCast();
        $book->update(['status' => BookStatus::Generating]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post("/admin/books/{$book->id}/stop")
            ->assertRedirect();

        $this->assertTrue(app(BookStopSignal::class)->requested($book->id));
    }

    public function test_non_admins_cannot_stop_a_book(): void
    {
        [, $book] = $this->pendingBookWithCast();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post("/admin/books/{$book->id}/stop")
            ->assertNotFound();

        $this->assertFalse(app(BookStopSignal::class)->requested($book->id));
    }

    public function test_admin_restyle_works_on_a_generating_book_and_stops_the_running_pipeline(): void
    {
        Queue::fake();

        [, $book] = $this->pendingBookWithCast();
        $book->update(['status' => BookStatus::Generating]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post("/admin/books/{$book->id}/restyle", ['artStyle' => 'watercolor'])
            ->assertRedirect();

        $book->refresh();
        $this->assertSame('watercolor', $book->art_style);
        $this->assertSame(BookStatus::Pending, $book->status);

        // The running pipeline was signalled to stop, and the restyle run is
        // queued behind it (its own start clears the signal).
        $this->assertTrue(app(BookStopSignal::class)->requested($book->id));
        Queue::assertPushed(GenerateStorybookJob::class, fn (GenerateStorybookJob $job): bool => $job->bookId === $book->id);
    }
}
