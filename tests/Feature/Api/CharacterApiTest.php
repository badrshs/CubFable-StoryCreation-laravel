<?php

namespace Tests\Feature\Api;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CharacterApiTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_DATA_URL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_index_lists_only_own_characters_newest_first()
    {
        $user = User::factory()->create();
        $older = Character::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $newer = Character::factory()->for($user)->create(['created_at' => now()]);
        Character::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.characters.index'));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_store_creates_a_character_with_a_photo()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.characters.store'), [
            'name' => 'Grandma Rose',
            'role' => 'grandmother',
            'ageGroup' => 'adult',
            'photoUrl' => self::PNG_DATA_URL,
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Grandma Rose');

        $character = Character::query()->firstOrFail();
        $this->assertNotNull($character->photo_path);
        Storage::disk('public')->assertExists($character->photo_path);
    }

    public function test_update_replaces_the_photo_and_clears_cached_appearance()
    {
        $user = User::factory()->create();
        $character = Character::factory()->for($user)->create(['appearance' => 'a girl with curly hair']);
        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.characters.update', ['id' => $character->id]), [
            'name' => $character->name,
            'photoUrl' => self::PNG_DATA_URL,
        ])->assertOk();

        $oldPath = $character->photo_path;
        $character->refresh();

        $this->assertNull($character->appearance);
        $this->assertNotNull($character->photo_path);
        $this->assertNotSame($oldPath, $character->photo_path);
        Storage::disk('public')->assertExists($character->photo_path);
    }

    public function test_update_deletes_the_previous_photo_file()
    {
        $user = User::factory()->create();
        $character = Character::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->patchJson(route('api.v1.characters.update', ['id' => $character->id]), [
            'name' => $character->name,
            'photoUrl' => self::PNG_DATA_URL,
        ])->assertOk();

        $firstPath = $character->refresh()->photo_path;

        $this->patchJson(route('api.v1.characters.update', ['id' => $character->id]), [
            'name' => $character->name,
            'photoUrl' => self::PNG_DATA_URL,
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($character->refresh()->photo_path);
    }

    public function test_destroy_is_silent_for_foreign_and_missing_ids()
    {
        $user = User::factory()->create();
        $own = Character::factory()->for($user)->create();
        $foreign = Character::factory()->create();
        Sanctum::actingAs($user);

        $this->deleteJson(route('api.v1.characters.destroy', ['id' => $own->id]))->assertNoContent();
        $this->assertDatabaseMissing('characters', ['id' => $own->id]);

        $this->deleteJson(route('api.v1.characters.destroy', ['id' => $foreign->id]))->assertNoContent();
        $this->assertDatabaseHas('characters', ['id' => $foreign->id]);

        $this->deleteJson(route('api.v1.characters.destroy', ['id' => 999999]))->assertNoContent();
    }
}
