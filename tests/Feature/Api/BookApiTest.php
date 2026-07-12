<?php

namespace Tests\Feature\Api;

use App\Enums\ArtStyle;
use App\Enums\BookStatus;
use App\Jobs\RegenerateCoverJob;
use App\Models\Book;
use App\Models\Page;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @return array<string, mixed>
     */
    private function wizardPayload(Template $template): array
    {
        return [
            'templateId' => $template->id,
            'ageRange' => '4-6',
            'theme' => 'forest',
            'subject' => 'a brave fox',
            'lifeLesson' => 'Kindness',
            'artStyle' => ArtStyle::cases()[0]->value,
            'font' => 'classic',
            'language' => 'en',
            'characters' => [
                [
                    'name' => 'Luna',
                    'ageGroup' => 'child',
                    'photoUrl' => self::PNG_DATA_URL,
                    'isMain' => true,
                ],
                [
                    'name' => 'Grandpa Joe',
                    'role' => 'grandfather',
                    'ageGroup' => 'adult',
                ],
            ],
        ];
    }

    public function test_store_creates_a_draft_with_cast_and_returns_the_reader_shape()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.books.store'), $this->wizardPayload($template));

        $response->assertCreated()
            ->assertJsonPath('data.childName', 'Luna')
            ->assertJsonPath('data.status', BookStatus::Draft->value)
            ->assertJsonPath('data.characters.0.isMain', true)
            ->assertJsonPath('data.characters.0.name', 'Luna')
            ->assertJsonPath('data.characters.1.isMain', false);

        $book = Book::query()->firstOrFail();
        $this->assertSame($user->id, $book->user_id);
        $this->assertSame(BookStatus::Draft, $book->status);
        $this->assertCount(2, $book->characters);
    }

    public function test_index_lists_only_own_books_with_progress_counts()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->generating()->create();
        Page::factory()->for($book)->complete()->create(['page_number' => 1]);
        Page::factory()->for($book)->create(['page_number' => 2]);
        Book::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.books.index'));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('data.0.pagesTotal', 2)
            ->assertJsonPath('data.0.pagesDone', 1);
    }

    public function test_show_returns_pages_and_cast_and_hides_foreign_books()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->complete()->create();
        Page::factory()->for($book)->complete()->create(['page_number' => 1]);
        $foreign = Book::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.books.show', ['id' => $book->id]))
            ->assertOk()
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonCount(1, 'data.pages');

        $this->getJson(route('api.v1.books.show', ['id' => $foreign->id]))->assertNotFound();
    }

    public function test_update_edits_a_draft_and_rejects_paid_books_with_409()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        $draft = Book::factory()->for($user)->for($template)->draft()->create();
        $paid = Book::factory()->for($user)->complete()->create();
        Sanctum::actingAs($user);

        $payload = [...$this->wizardPayload($template), 'subject' => 'a curious owl'];
        unset($payload['templateId']);

        $this->patchJson(route('api.v1.books.update', ['id' => $draft->id]), $payload)
            ->assertOk()
            ->assertJsonPath('data.subject', 'a curious owl');

        $this->patchJson(route('api.v1.books.update', ['id' => $paid->id]), $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'book_not_editable');
    }

    public function test_destroy_removes_drafts_only()
    {
        $user = User::factory()->create();
        $draft = Book::factory()->for($user)->draft()->create();
        $paid = Book::factory()->for($user)->complete()->create();
        Sanctum::actingAs($user);

        $this->deleteJson(route('api.v1.books.destroy', ['id' => $draft->id]))->assertNoContent();
        $this->assertDatabaseMissing('books', ['id' => $draft->id]);

        $this->deleteJson(route('api.v1.books.destroy', ['id' => $paid->id]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'book_not_editable');
        $this->assertDatabaseHas('books', ['id' => $paid->id]);
    }

    public function test_regenerate_cover_requires_payment_and_dispatches_the_job()
    {
        Queue::fake();

        $user = User::factory()->create();
        $draft = Book::factory()->for($user)->draft()->create();
        $paid = Book::factory()->for($user)->complete()->create();
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.books.regenerate-cover', ['id' => $draft->id]))
            ->assertStatus(402)
            ->assertJsonPath('code', 'payment_required');
        Queue::assertNotPushed(RegenerateCoverJob::class);

        $this->postJson(route('api.v1.books.regenerate-cover', ['id' => $paid->id]))
            ->assertStatus(202)
            ->assertJsonPath('data.coverStatus', 'generating');
        Queue::assertPushed(RegenerateCoverJob::class);
    }

    public function test_restyle_validates_state_and_requeues_the_book()
    {
        Queue::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $draft = Book::factory()->for($user)->draft()->create();
        $this->postJson(route('api.v1.books.restyle', ['id' => $draft->id]), [
            'artStyle' => ArtStyle::cases()[0]->value,
        ])->assertStatus(402);

        $generating = Book::factory()->for($user)->generating()->create();
        $this->postJson(route('api.v1.books.restyle', ['id' => $generating->id]), [
            'artStyle' => ArtStyle::cases()[0]->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('artStyle');

        $complete = Book::factory()->for($user)->complete()->create();
        $newStyle = ArtStyle::cases()[1]->value;

        $this->postJson(route('api.v1.books.restyle', ['id' => $complete->id]), [
            'artStyle' => $newStyle,
        ])->assertStatus(202)->assertJsonPath('data.status', BookStatus::Pending->value);

        $this->assertSame($newStyle, $complete->refresh()->art_style);
    }
}
