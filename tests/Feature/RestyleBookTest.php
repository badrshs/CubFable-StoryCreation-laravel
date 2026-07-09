<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RestyleBookTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
    }

    public function test_restyling_clears_images_and_requeues_generation_with_the_new_style(): void
    {
        [$user, $book] = $this->completeBookWithImages();

        $oldCover = $book->cover_image_path;
        $oldPageImage = $book->pages()->first()->image_path;

        $this->actingAs($user)
            ->post(route('books.restyle', ['id' => $book->id]), ['artStyle' => 'crayon'])
            ->assertRedirect();

        $book->refresh();
        $this->assertSame('crayon', $book->art_style);
        $this->assertSame(BookStatus::Pending, $book->status);
        $this->assertNull($book->cover_image_path);
        $this->assertNull($book->hero_sheet_path);

        foreach ($book->pages as $page) {
            $this->assertNull($page->image_path);
            $this->assertSame(PageStatus::Generating, $page->status);
            // The story survives the restyle untouched.
            $this->assertNotSame('', $page->text);
        }

        // The old art stays on disk as restorable versions and generation is
        // queued again.
        Storage::disk('public')->assertExists((string) $oldCover);
        Storage::disk('public')->assertExists((string) $oldPageImage);
        $this->assertDatabaseHas('image_versions', ['book_id' => $book->id, 'slot' => 'cover', 'path' => $oldCover]);
        $this->assertDatabaseHas('image_versions', ['book_id' => $book->id, 'slot' => 'page', 'path' => $oldPageImage]);
        Queue::assertPushed(GenerateStorybookJob::class, fn (GenerateStorybookJob $job): bool => $job->bookId === $book->id);
    }

    public function test_a_generating_book_cannot_be_restyled(): void
    {
        [$user, $book] = $this->completeBookWithImages();
        $book->update(['status' => BookStatus::Generating]);

        $this->actingAs($user)
            ->from(route('books.show', ['id' => $book->id]))
            ->post(route('books.restyle', ['id' => $book->id]), ['artStyle' => 'crayon'])
            ->assertSessionHasErrors('artStyle');

        Queue::assertNothingPushed();
    }

    public function test_a_draft_cannot_be_restyled(): void
    {
        [$user, $book] = $this->completeBookWithImages();
        $book->update(['status' => BookStatus::Draft]);

        $this->actingAs($user)
            ->post(route('books.restyle', ['id' => $book->id]), ['artStyle' => 'crayon'])
            ->assertStatus(402);
    }

    public function test_an_unknown_style_is_rejected(): void
    {
        [$user, $book] = $this->completeBookWithImages();

        $this->actingAs($user)
            ->from(route('books.show', ['id' => $book->id]))
            ->post(route('books.restyle', ['id' => $book->id]), ['artStyle' => 'oil-paint'])
            ->assertSessionHasErrors('artStyle');

        $this->assertSame('storybook', $book->refresh()->art_style);
        Queue::assertNothingPushed();
    }

    public function test_a_strangers_book_is_not_found(): void
    {
        [, $book] = $this->completeBookWithImages();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('books.restyle', ['id' => $book->id]), ['artStyle' => 'crayon'])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Book}
     */
    private function completeBookWithImages(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 2]);

        $book = Book::factory()->complete()->for($user)->for($template)->create([
            'art_style' => 'storybook',
        ]);

        $cover = "books/{$book->id}/cover-old.png";
        Storage::disk('public')->put($cover, (string) base64_decode(self::PNG_BASE64, true));
        $book->update(['cover_image_path' => $cover]);

        foreach ([1, 2] as $number) {
            $path = "books/{$book->id}/pages/{$number}-old.png";
            Storage::disk('public')->put($path, (string) base64_decode(self::PNG_BASE64, true));

            Page::query()->create([
                'book_id' => $book->id,
                'page_number' => $number,
                'text' => "Page {$number} text.",
                'scene' => "Page {$number} scene.",
                'image_path' => $path,
                'status' => PageStatus::Complete,
            ]);
        }

        return [$user, $book];
    }
}
