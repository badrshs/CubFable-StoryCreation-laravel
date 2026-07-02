<?php

namespace Tests\Feature;

use App\Enums\PageStatus;
use App\Jobs\RegeneratePageJob;
use App\Models\Book;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PageEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_can_update_a_pages_text_and_is_redirected_back(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create();
        $page = Page::factory()->for($book)->complete()->create([
            'page_number' => 1,
            'text' => 'Original text.',
        ]);

        $response = $this->actingAs($user)
            ->from(route('books.show', ['id' => $book->id]))
            ->patch(route('pages.update', ['id' => $book->id, 'pageId' => $page->id]), [
                'text' => 'A brand new paragraph.',
            ]);

        $response->assertRedirect(route('books.show', ['id' => $book->id]));
        $this->assertSame('A brand new paragraph.', $page->refresh()->text);
    }

    public function test_text_over_twenty_thousand_characters_is_rejected(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create();
        $page = Page::factory()->for($book)->complete()->create([
            'page_number' => 1,
            'text' => 'Original text.',
        ]);

        $response = $this->actingAs($user)
            ->from(route('books.show', ['id' => $book->id]))
            ->patch(route('pages.update', ['id' => $book->id, 'pageId' => $page->id]), [
                'text' => str_repeat('a', 20001),
            ]);

        $response->assertSessionHasErrors('text');
        $this->assertSame('Original text.', $page->refresh()->text);
    }

    public function test_a_page_belonging_to_a_different_book_is_not_found(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create();
        $otherBook = Book::factory()->complete()->for($user)->create();
        $foreignPage = Page::factory()->for($otherBook)->complete()->create([
            'page_number' => 1,
            'text' => 'Original text.',
        ]);

        $this->actingAs($user)
            ->patch(route('pages.update', ['id' => $book->id, 'pageId' => $foreignPage->id]), [
                'text' => 'Cross-book write.',
            ])
            ->assertNotFound();

        $this->assertSame('Original text.', $foreignPage->refresh()->text);
    }

    public function test_regenerating_a_page_of_an_unpaid_draft_requires_payment(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();
        $page = Page::factory()->for($book)->complete()->create(['page_number' => 1]);

        $this->actingAs($user)
            ->post(route('pages.regenerate', ['id' => $book->id, 'pageId' => $page->id]))
            ->assertStatus(402);

        $this->assertSame(PageStatus::Complete, $page->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_regenerating_a_page_of_a_paid_book_marks_it_generating_and_queues_the_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create();
        $page = Page::factory()->for($book)->complete()->create(['page_number' => 1]);

        $response = $this->actingAs($user)
            ->from(route('books.show', ['id' => $book->id]))
            ->post(route('pages.regenerate', ['id' => $book->id, 'pageId' => $page->id]));

        $response->assertRedirect(route('books.show', ['id' => $book->id]));
        $this->assertSame(PageStatus::Generating, $page->refresh()->status);

        Queue::assertPushed(RegeneratePageJob::class, 1);
        Queue::assertPushed(RegeneratePageJob::class, fn (RegeneratePageJob $job): bool => $job->pageId === $page->id);
    }
}
