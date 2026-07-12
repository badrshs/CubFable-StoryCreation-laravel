<?php

namespace Tests\Feature\Api;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_returns_progress_counts_and_per_page_statuses()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->generating()->create(['cover_status' => 'generating']);
        Page::factory()->for($book)->complete()->create(['page_number' => 1]);
        Page::factory()->for($book)->create(['page_number' => 2]);
        Page::factory()->for($book)->create(['page_number' => 3]);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.books.status', ['id' => $book->id]));

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', BookStatus::Generating->value)
            ->assertJsonPath('data.coverStatus', 'generating')
            ->assertJsonPath('data.pagesTotal', 3)
            ->assertJsonPath('data.pagesDone', 1)
            ->assertJsonPath('data.pages.0.pageNumber', 1)
            ->assertJsonPath('data.pages.0.status', 'complete')
            ->assertJsonPath('data.pages.1.status', 'pending');

        $this->assertNotNull($response->json('data.pages.0.imageUrl'));
        $this->assertNull($response->json('data.pages.1.imageUrl'));
    }

    public function test_status_is_owner_scoped()
    {
        $user = User::factory()->create();
        $foreign = Book::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.books.status', ['id' => $foreign->id]))->assertNotFound();
    }

    public function test_status_requires_authentication()
    {
        $book = Book::factory()->create();

        $this->getJson(route('api.v1.books.status', ['id' => $book->id]))->assertUnauthorized();
    }
}
