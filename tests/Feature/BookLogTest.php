<?php

namespace Tests\Feature;

use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Models\User;
use App\Services\StoryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookLogTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $logFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->logFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    private function logPath(int $bookId): string
    {
        $path = storage_path("logs/books/book-{$bookId}.log");
        $this->logFiles[] = $path;
        File::delete($path);

        return $path;
    }

    public function test_records_with_a_book_id_land_in_that_books_own_file(): void
    {
        $path = $this->logPath(424242);

        Log::info('hello from the book', ['book_id' => 424242]);
        Log::error('and an error too', ['book_id' => 424242]);
        Log::info('unrelated line without a book');

        $this->assertFileExists($path);
        $content = (string) File::get($path);
        $this->assertStringContainsString('INFO: hello from the book', $content);
        $this->assertStringContainsString('ERROR: and an error too', $content);
        $this->assertStringNotContainsString('unrelated line', $content);
    }

    public function test_shared_context_routes_records_without_explicit_context(): void
    {
        $path = $this->logPath(424243);

        Context::add('book_id', 424243);
        Log::warning('a warning that only carries shared context');
        Context::forget('book_id');

        $this->assertFileExists($path);
        $this->assertStringContainsString('WARNING: a warning that only carries shared context', (string) File::get($path));
    }

    public function test_a_full_generation_run_writes_the_a_to_z_log(): void
    {
        config()->set('cubfable.ai.text_provider', 'openai');
        config()->set('cubfable.ai.image_provider', 'openai');
        config()->set('cubfable.ai.keys.openai', 'test-key');
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 1]);
        $book = Book::factory()->pending()->for($user)->for($template)->create(['child_name' => 'Mia', 'theme' => 'forest', 'language' => 'en']);
        $character = Character::factory()->for($user)->create([
            'name' => 'Mia',
            'appearance' => 'Short curly brown hair, yellow raincoat.',
        ]);
        $book->characters()->attach($character->id, ['is_main' => true, 'sort_order' => 0]);

        $path = $this->logPath($book->id);

        $story = [['text' => 'Mia finds a lantern.', 'scene' => 'Mia holds a glowing lantern.']];
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode($story)]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
            'api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
            'api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => self::PNG_BASE64]]]),
        ]);

        (new GenerateStorybookJob($book->id))->handle(app(StoryGenerator::class));

        Context::forget('book_id');

        $this->assertFileExists($path);
        $content = (string) File::get($path);
        $this->assertStringContainsString('Generation run started.', $content);
        $this->assertStringContainsString('Story blueprint written and persisted.', $content);
        $this->assertStringContainsString('Cover stored.', $content);
        $this->assertStringContainsString('Page 1 illustration stored.', $content);
        $this->assertStringContainsString('Book generation complete.', $content);
    }

    public function test_admin_can_view_the_log_and_destroy_removes_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $book = Book::factory()->complete()->create();
        $path = $this->logPath($book->id);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'the whole story of this book');

        $this->actingAs($admin)
            ->get("/admin/books/{$book->id}/log")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('the whole story of this book');

        $this->actingAs($admin)->delete("/admin/books/{$book->id}")->assertRedirect();
        $this->assertFileDoesNotExist($path);

        $member = User::factory()->create(['is_admin' => false]);
        $this->actingAs($member)->get("/admin/books/{$book->id}/log")->assertNotFound();
    }
}
