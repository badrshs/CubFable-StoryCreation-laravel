<?php

namespace Tests\Feature\Admin;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\ImagePrompt;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBooksTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function book(array $attributes = [], int $pages = 2, bool $withImages = true): Book
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => $pages]);
        $book = Book::factory()->complete()->for($user)->for($template)->create($attributes);

        $cover = "books/{$book->id}/cover.png";
        Storage::disk('public')->put($cover, (string) base64_decode(self::PNG_BASE64, true));
        $book->update(['cover_image_path' => $cover]);

        foreach (range(1, $pages) as $number) {
            $path = null;

            if ($withImages) {
                $path = "books/{$book->id}/pages/{$number}.png";
                Storage::disk('public')->put($path, (string) base64_decode(self::PNG_BASE64, true));
            }

            Page::query()->create([
                'book_id' => $book->id,
                'page_number' => $number,
                'text' => "Page {$number}",
                'scene' => "Scene {$number}",
                'image_path' => $path,
                'status' => $withImages ? PageStatus::Complete : PageStatus::Generating,
            ]);
        }

        return $book;
    }

    public function test_the_index_lists_and_searches_books(): void
    {
        $book = $this->book(['child_name' => 'Zainab']);
        $this->book(['child_name' => 'Oliver']);

        $this->withoutVite()
            ->actingAs($this->admin())
            ->get('/admin/books?search=Zainab')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/books/index')
                ->has('books.data', 1)
                ->where('books.data.0.childName', 'Zainab')
                ->where('books.data.0.id', $book->id));
    }

    public function test_the_show_page_carries_the_prompt_journal(): void
    {
        $book = $this->book();
        $book->pages()->first()->update(['image_prompt' => 'a prompt']);

        ImagePrompt::query()->create([
            'book_id' => $book->id,
            'page_id' => $book->pages()->first()->id,
            'purpose' => 'page',
            'attempt' => 1,
            'variant' => 'original',
            'prompt' => 'draw the fox',
            'accepted' => true,
        ]);

        $this->withoutVite()
            ->actingAs($this->admin())
            ->get("/admin/books/{$book->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/books/show')
                ->where('book.id', $book->id)
                ->has('journal', 1)
                ->where('journal.0.prompt', 'draw the fox')
                ->where('journal.0.pageNumber', 1));
    }

    public function test_resume_requeues_a_failed_book(): void
    {
        Queue::fake();
        $book = $this->book(['status' => BookStatus::Failed]);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/resume")
            ->assertRedirect();

        $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        Queue::assertPushed(GenerateStorybookJob::class);
    }

    public function test_resume_refuses_a_complete_book(): void
    {
        Queue::fake();
        $book = $this->book();

        $this->actingAs($this->admin())
            ->from("/admin/books/{$book->id}")
            ->post("/admin/books/{$book->id}/resume")
            ->assertSessionHasErrors('book');

        Queue::assertNothingPushed();
    }

    public function test_heal_completes_a_stranded_book_with_full_content(): void
    {
        $book = $this->book(['status' => BookStatus::Generating]);

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/heal")
            ->assertRedirect();

        $this->assertSame(BookStatus::Complete, $book->refresh()->status);
    }

    public function test_heal_reports_missing_content(): void
    {
        $book = $this->book(['status' => BookStatus::Generating], pages: 2, withImages: false);

        $this->actingAs($this->admin())
            ->from("/admin/books/{$book->id}")
            ->post("/admin/books/{$book->id}/heal")
            ->assertSessionHasErrors('book');

        $this->assertSame(BookStatus::Generating, $book->refresh()->status);
    }

    public function test_restart_wipes_everything_and_requeues_a_complete_book(): void
    {
        Queue::fake();
        $book = $this->book([
            'story_bible' => ['subtitle' => 'and the Lantern', 'motif' => 'a ladybug'],
            'cover_prompt' => 'old cover prompt',
        ]);

        $sheet = "books/{$book->id}/sheet.png";
        Storage::disk('public')->put($sheet, (string) base64_decode(self::PNG_BASE64, true));
        $book->update(['hero_sheet_path' => $sheet, 'hero_sheet_prompt' => 'old sheet prompt']);

        $cover = (string) $book->cover_image_path;
        $pageImage = (string) $book->pages()->first()->image_path;

        $this->actingAs($this->admin())
            ->post("/admin/books/{$book->id}/restart")
            ->assertRedirect();

        $book->refresh();
        $this->assertSame(BookStatus::Pending, $book->status);
        $this->assertNull($book->story_bible);
        $this->assertNull($book->cover_image_path);
        $this->assertNull($book->cover_prompt);
        $this->assertNull($book->hero_sheet_path);
        $this->assertNull($book->hero_sheet_prompt);
        $this->assertSame(0, $book->pages()->count());

        // Nothing is deleted: the old images stay as restorable versions
        // that survive the page rows being recreated (page_number anchor).
        Storage::disk('public')->assertExists($cover);
        Storage::disk('public')->assertExists($sheet);
        Storage::disk('public')->assertExists($pageImage);
        $this->assertDatabaseHas('image_versions', ['book_id' => $book->id, 'slot' => 'cover', 'path' => $cover]);
        $this->assertDatabaseHas('image_versions', ['book_id' => $book->id, 'slot' => 'page', 'page_number' => 1, 'path' => $pageImage, 'page_id' => null]);

        Queue::assertPushed(GenerateStorybookJob::class);
    }

    public function test_restart_works_regardless_of_status(): void
    {
        Queue::fake();

        foreach ([BookStatus::Draft, BookStatus::Generating, BookStatus::Failed] as $status) {
            $book = $this->book(['status' => $status]);

            $this->actingAs($this->admin())
                ->post("/admin/books/{$book->id}/restart")
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertSame(BookStatus::Pending, $book->refresh()->status);
        }

        Queue::assertPushed(GenerateStorybookJob::class, 3);
    }

    public function test_destroy_removes_the_book_and_its_files(): void
    {
        Queue::fake();
        $book = $this->book();
        $cover = (string) $book->cover_image_path;

        $this->actingAs($this->admin())
            ->delete("/admin/books/{$book->id}")
            ->assertRedirect(route('admin.books'));

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        Storage::disk('public')->assertMissing($cover);
    }

    public function test_non_admins_get_404_everywhere(): void
    {
        $book = $this->book();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/admin/books')->assertNotFound();
        $this->actingAs($user)->get("/admin/books/{$book->id}")->assertNotFound();
        $this->actingAs($user)->post("/admin/books/{$book->id}/resume")->assertNotFound();
        $this->actingAs($user)->post("/admin/books/{$book->id}/restart")->assertNotFound();
        $this->actingAs($user)->delete("/admin/books/{$book->id}")->assertNotFound();
    }
}
