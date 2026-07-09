<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_a_complete_book_downloads_as_a_pdf_attachment(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create([
            'child_name' => 'Luna Rose',
        ]);

        foreach ([1, 2] as $number) {
            Page::factory()->for($book)->complete()->create(['page_number' => $number]);
        }

        $response = $this->actingAs($user)->get(route('books.download', ['id' => $book->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertDownload('luna-rose-cubfable-storybook-print.pdf');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_the_home_variant_downloads_with_its_own_filename(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create([
            'child_name' => 'Luna Rose',
        ]);

        $this->actingAs($user)
            ->get(route('books.download', ['id' => $book->id, 'variant' => 'home']))
            ->assertOk()
            ->assertDownload('luna-rose-cubfable-storybook-home.pdf');
    }

    public function test_a_child_name_with_no_sluggable_characters_falls_back_to_a_generic_filename(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create([
            'child_name' => '李娜',
        ]);

        $this->actingAs($user)
            ->get(route('books.download', ['id' => $book->id]))
            ->assertOk()
            ->assertDownload('storybook-cubfable-storybook-print.pdf');
    }

    public function test_an_unpaid_draft_still_streams_a_pdf_built_from_fallbacks(): void
    {
        // The download route has no payment guard: it composes whatever the
        // book currently holds, drawing gradient placeholders for any missing
        // illustrations (mirrors BookDownloadController, which only scopes by
        // owner before building).
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create([
            'child_name' => 'Zoe',
            'cover_image_path' => null,
        ]);

        Page::factory()->for($book)->create([
            'page_number' => 1,
            'image_path' => null,
        ]);

        $response = $this->actingAs($user)->get(route('books.download', ['id' => $book->id]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $book = Book::factory()->complete()->create();

        $this->get(route('books.download', ['id' => $book->id]))
            ->assertRedirect(route('login'));
    }
}
