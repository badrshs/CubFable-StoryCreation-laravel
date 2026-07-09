<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\Character;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BookDraftEditingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_wizard_reopens_prefilled_for_a_draft(): void
    {
        [$user, $book, $hero] = $this->draftWithHero();

        $this->actingAs($user)
            ->get(route('books.edit', ['id' => $book->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('create-wizard')
                ->where('book.id', $book->id)
                ->where('book.childName', $book->child_name)
                ->where('book.characters.0.id', $hero->id)
                ->where('book.characters.0.isMain', true)
                ->has('template')
                ->has('savedCharacters'));
    }

    public function test_the_edit_page_redirects_to_the_reader_for_a_paid_book(): void
    {
        [$user, $book] = $this->draftWithHero();
        $book->update(['status' => BookStatus::Pending]);

        $this->actingAs($user)
            ->get(route('books.edit', ['id' => $book->id]))
            ->assertRedirect(route('books.show', ['id' => $book->id], absolute: false));
    }

    public function test_editing_someone_elses_draft_is_a_404(): void
    {
        [, $book] = $this->draftWithHero();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->get(route('books.edit', ['id' => $book->id]))
            ->assertNotFound();
        $this->actingAs($stranger)
            ->patch(route('books.update', ['id' => $book->id]), $this->payload('Nora'))
            ->assertNotFound();
        $this->actingAs($stranger)
            ->delete(route('books.destroy', ['id' => $book->id]))
            ->assertNotFound();
    }

    public function test_updating_a_draft_changes_fields_and_rebuilds_the_cast(): void
    {
        [$user, $book, $hero] = $this->draftWithHero();
        $removed = Character::factory()->for($user)->create(['name' => 'Old Sidekick']);
        $book->characters()->attach($removed->id, ['is_main' => false, 'sort_order' => 1]);

        $payload = $this->payload('Nora', heroCharacterId: $hero->id);
        $payload['characters'][] = [
            'name' => 'Grandpa Joe',
            'role' => 'grandfather',
            'isMain' => false,
        ];

        $this->actingAs($user)
            ->patch(route('books.update', ['id' => $book->id]), $payload)
            ->assertRedirect(route('checkout.show', ['id' => $book->id], absolute: false));

        $book->refresh();
        $this->assertSame('Nora', $book->child_name);
        $this->assertSame('6-8', $book->age_range);
        $this->assertSame('crayon', $book->art_style);
        $this->assertSame(BookStatus::Draft, $book->status);

        $cast = $book->characters()->get();
        $this->assertCount(2, $cast);
        $this->assertSame('Nora', $cast->first()->name);
        $this->assertSame('Grandpa Joe', $cast->last()->name);
        $this->assertFalse($cast->contains('id', $removed->id));

        // Dropped from the cast, but never from the library.
        $this->assertDatabaseHas('characters', ['id' => $removed->id, 'name' => 'Old Sidekick']);
    }

    public function test_updating_with_a_new_photo_stores_it_and_clears_the_cached_appearance(): void
    {
        [$user, $book, $hero] = $this->draftWithHero();
        $hero->update(['appearance' => 'cached description']);

        $payload = $this->payload($hero->name, heroCharacterId: $hero->id);
        $payload['characters'][0]['photoUrl'] = self::PNG_DATA_URL;

        $this->actingAs($user)
            ->patch(route('books.update', ['id' => $book->id]), $payload)
            ->assertRedirect(route('checkout.show', ['id' => $book->id], absolute: false));

        $hero->refresh();
        $this->assertNotNull($hero->photo_path);
        Storage::disk('public')->assertExists($hero->photo_path);
        $this->assertNull($hero->appearance);
    }

    public function test_updating_a_paid_book_is_rejected(): void
    {
        [$user, $book] = $this->draftWithHero();
        $book->update(['status' => BookStatus::Pending]);
        $original = $book->child_name;

        $this->actingAs($user)
            ->patch(route('books.update', ['id' => $book->id]), $this->payload('Nora'))
            ->assertRedirect(route('books.show', ['id' => $book->id], absolute: false));

        $this->assertSame($original, $book->refresh()->child_name);
    }

    public function test_deleting_a_draft_removes_it_but_keeps_the_characters(): void
    {
        [$user, $book, $hero] = $this->draftWithHero();
        $order = Order::factory()->pending()->for($user)->for($book)->create();

        $this->actingAs($user)
            ->delete(route('books.destroy', ['id' => $book->id]))
            ->assertRedirect(route('books.index', absolute: false));

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('book_characters', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('characters', ['id' => $hero->id]);
    }

    public function test_deleting_a_paid_book_is_rejected(): void
    {
        [$user, $book] = $this->draftWithHero();
        $book->update(['status' => BookStatus::Pending]);

        $this->actingAs($user)
            ->delete(route('books.destroy', ['id' => $book->id]))
            ->assertRedirect(route('books.show', ['id' => $book->id], absolute: false));

        $this->assertDatabaseHas('books', ['id' => $book->id]);
    }

    /**
     * A draft book owned by a fresh user, with a hero character attached.
     *
     * @return array{0: User, 1: Book, 2: Character}
     */
    private function draftWithHero(): array
    {
        $user = User::factory()->create();
        $template = Template::factory()->create(['page_count' => 3]);

        $book = Book::factory()->draft()->for($user)->for($template)->create([
            'child_name' => 'Mia',
            'age_range' => '4-6',
            'art_style' => 'watercolor',
        ]);

        $hero = Character::factory()->for($user)->create(['name' => 'Mia', 'role' => 'self']);
        $book->characters()->attach($hero->id, ['is_main' => true, 'sort_order' => 0]);

        return [$user, $book, $hero];
    }

    /**
     * A minimal valid wizard update payload (original camelCase field names).
     *
     * @return array<string, mixed>
     */
    private function payload(string $heroName, ?int $heroCharacterId = null): array
    {
        return [
            'childName' => $heroName,
            'ageRange' => '6-8',
            'theme' => 'forest',
            'subject' => 'Camping',
            'lifeLesson' => 'Courage',
            'artStyle' => 'crayon',
            'font' => 'classic',
            'language' => 'en',
            'characters' => [
                [
                    'characterId' => $heroCharacterId,
                    'name' => $heroName,
                    'role' => 'self',
                    'isMain' => true,
                ],
            ],
        ];
    }
}
