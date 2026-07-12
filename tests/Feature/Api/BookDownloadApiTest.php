<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookDownloadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_bearer_token_downloads_the_pdf()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->complete()->create(['child_name' => 'Luna']);
        Page::factory()->for($book)->complete()->create(['page_number' => 1]);
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withToken($token)->get(route('api.v1.books.download', ['id' => $book->id, 'variant' => 'home']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('luna-cubfable-storybook-home.pdf');
    }

    public function test_foreign_books_are_not_found()
    {
        $user = User::factory()->create();
        $foreign = Book::factory()->complete()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)
            ->getJson(route('api.v1.books.download', ['id' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_download_requires_a_token()
    {
        $book = Book::factory()->complete()->create();

        $this->getJson(route('api.v1.books.download', ['id' => $book->id]))->assertUnauthorized();
    }
}
