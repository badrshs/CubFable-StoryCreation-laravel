<?php

namespace Tests\Feature\Api;

use App\Enums\PageStatus;
use App\Jobs\RegeneratePageJob;
use App\Models\Book;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_text_can_be_edited_on_own_books()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->complete()->create();
        $page = Page::factory()->for($book)->complete()->create(['page_number' => 1]);
        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.pages.update', ['id' => $book->id, 'pageId' => $page->id]), [
            'text' => 'Luna tiptoed into the whispering wood.',
        ]);

        $response->assertOk()->assertJsonPath('data.text', 'Luna tiptoed into the whispering wood.');
        $this->assertSame('Luna tiptoed into the whispering wood.', $page->refresh()->text);
    }

    public function test_pages_of_foreign_books_are_not_found()
    {
        $user = User::factory()->create();
        $foreign = Book::factory()->complete()->create();
        $page = Page::factory()->for($foreign)->complete()->create(['page_number' => 1]);
        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.pages.update', ['id' => $foreign->id, 'pageId' => $page->id]), [
            'text' => 'hijacked',
        ])->assertNotFound();
    }

    public function test_regenerate_requires_a_paid_book()
    {
        Queue::fake();

        $user = User::factory()->create();
        $draft = Book::factory()->for($user)->draft()->create();
        $page = Page::factory()->for($draft)->create(['page_number' => 1]);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.pages.regenerate', ['id' => $draft->id, 'pageId' => $page->id]))
            ->assertStatus(402)
            ->assertJsonPath('code', 'payment_required');

        Queue::assertNotPushed(RegeneratePageJob::class);
    }

    public function test_regenerate_marks_the_page_generating_and_dispatches_the_job()
    {
        Queue::fake();

        $user = User::factory()->create();
        $book = Book::factory()->for($user)->complete()->create();
        $page = Page::factory()->for($book)->complete()->create(['page_number' => 1]);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.pages.regenerate', ['id' => $book->id, 'pageId' => $page->id]));

        $response->assertStatus(202)->assertJsonPath('data.status', PageStatus::Generating->value);
        $this->assertSame(PageStatus::Generating, $page->refresh()->status);
        Queue::assertPushed(RegeneratePageJob::class);
    }
}
