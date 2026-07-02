<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A valid 1x1 PNG as the wizard submits photos: a base64 data URL.
     */
    private const PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_the_wizard_creates_a_draft_book_with_its_cast_and_redirects_to_checkout(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();

        $response = $this->actingAs($user)->post(route('books.store'), $this->payload($template, [
            'characters' => [
                ['name' => 'Captain Whiskers', 'role' => 'pet', 'description' => 'A ginger cat with one folded ear'],
                ['name' => 'Luna', 'role' => 'self', 'photoUrl' => self::PNG_DATA_URL, 'isMain' => true],
            ],
        ]));

        $book = Book::query()->sole();

        $response->assertRedirect(route('checkout.show', ['id' => $book->id]));

        $this->assertSame(BookStatus::Draft, $book->status);
        $this->assertNull($book->paid_at);
        $this->assertSame($user->id, $book->user_id);
        $this->assertSame($template->id, $book->template_id);
        $this->assertSame('Luna', $book->child_name);
        $this->assertSame('4-6', $book->age_range);
        $this->assertSame('Moonlit Forest', $book->theme);
        $this->assertSame('watercolor', $book->art_style);
        $this->assertSame('en', $book->language);

        $sidekick = Character::query()->where('name', 'Captain Whiskers')->sole();
        $hero = Character::query()->where('name', 'Luna')->sole();

        $this->assertDatabaseHas('book_characters', [
            'book_id' => $book->id,
            'character_id' => $sidekick->id,
            'is_main' => false,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('book_characters', [
            'book_id' => $book->id,
            'character_id' => $hero->id,
            'is_main' => true,
            'sort_order' => 1,
        ]);

        // The hero's photo data URL was decoded onto the public disk.
        $this->assertNotNull($hero->photo_path);
        Storage::disk('public')->assertExists($hero->photo_path);
        $this->assertNull($sidekick->photo_path);

        // The hero (is_main) leads the book's cast ordering.
        $this->assertSame($hero->id, $book->characters()->first()->id);
    }

    public function test_an_unknown_art_style_is_rejected(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();

        $response = $this->actingAs($user)->post(route('books.store'), $this->payload($template, [
            'artStyle' => 'oil-paint',
        ]));

        $response->assertSessionHasErrors('artStyle');
        $this->assertSame(0, Book::query()->count());
        $this->assertSame(0, Character::query()->count());
    }

    public function test_at_least_one_character_is_required(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();

        $response = $this->actingAs($user)->post(route('books.store'), $this->payload($template, [
            'characters' => [],
        ]));

        $response->assertSessionHasErrors('characters');
        $this->assertSame(0, Book::query()->count());
    }

    public function test_more_than_twenty_four_characters_are_rejected(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();

        $response = $this->actingAs($user)->post(route('books.store'), $this->payload($template, [
            'characters' => array_map(
                fn (int $index): array => ['name' => "Friend {$index}"],
                range(1, 25),
            ),
        ]));

        $response->assertSessionHasErrors('characters');
        $this->assertSame(0, Book::query()->count());
        $this->assertSame(0, Character::query()->count());
    }

    public function test_reusing_a_saved_character_links_it_instead_of_creating_a_new_one(): void
    {
        $user = User::factory()->create();
        $template = Template::factory()->create();
        $saved = Character::factory()->for($user)->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->post(route('books.store'), $this->payload($template, [
            'characters' => [
                ['characterId' => $saved->id, 'name' => 'New Name', 'isMain' => true],
            ],
        ]));

        $book = Book::query()->sole();

        $response->assertRedirect(route('checkout.show', ['id' => $book->id]));

        $this->assertSame(1, Character::query()->count());
        $this->assertTrue($book->characters()->whereKey($saved->id)->exists());
        $this->assertSame('New Name', $saved->refresh()->name);
        $this->assertSame('New Name', $book->child_name);
    }

    public function test_a_foreign_character_id_never_attaches_another_users_character(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $foreign = Character::factory()->for($stranger)->create(['name' => 'Foreign Original']);
        $template = Template::factory()->create();

        $response = $this->actingAs($user)->post(route('books.store'), $this->payload($template, [
            'characters' => [
                ['characterId' => $foreign->id, 'name' => 'Impostor', 'isMain' => true],
            ],
        ]));

        $book = Book::query()->sole();

        $response->assertRedirect(route('checkout.show', ['id' => $book->id]));

        // The foreign id falls through to creating a fresh character owned by
        // the caller; the other account's character is never attached.
        $cast = $book->characters;
        $this->assertCount(1, $cast);
        $this->assertNotSame($foreign->id, $cast->first()->id);
        $this->assertSame($user->id, $cast->first()->user_id);
        $this->assertSame('Impostor', $cast->first()->name);

        // And the other user's character is left untouched.
        $foreign->refresh();
        $this->assertSame('Foreign Original', $foreign->name);
        $this->assertSame($stranger->id, $foreign->user_id);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $template = Template::factory()->create();

        $response = $this->post(route('books.store'), $this->payload($template));

        $response->assertRedirect(route('login'));
        $this->assertSame(0, Book::query()->count());
    }

    /**
     * A valid wizard payload using the original camelCase API field names.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Template $template, array $overrides = []): array
    {
        return [
            'templateId' => $template->id,
            'ageRange' => '4-6',
            'theme' => 'Moonlit Forest',
            'subject' => 'a brave little fox',
            'lifeLesson' => 'Courage',
            'artStyle' => 'watercolor',
            'font' => 'playful',
            'language' => 'en',
            'characters' => [
                ['name' => 'Luna', 'role' => 'self', 'isMain' => true],
            ],
            ...$overrides,
        ];
    }
}
