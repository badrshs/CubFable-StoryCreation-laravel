<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CharacterLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_library_character_saves_its_age_group(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('characters.store'), [
            'name' => 'Grandpa Joe',
            'role' => 'grandfather',
            'ageGroup' => 'adult',
        ])->assertSessionHasNoErrors();

        $this->assertSame('adult', Character::query()->sole()->age_group);
    }

    public function test_updating_a_library_character_changes_its_age_group_only_when_sent(): void
    {
        $user = User::factory()->create();
        $character = Character::factory()->for($user)->create([
            'name' => 'Grandpa Joe',
            'age_group' => 'adult',
        ]);

        // PATCH semantics: an update that omits ageGroup leaves it alone.
        $this->actingAs($user)->patch(route('characters.update', ['id' => $character->id]), [
            'name' => 'Grandpa Joseph',
        ])->assertSessionHasNoErrors();

        $this->assertSame('adult', $character->refresh()->age_group);

        $this->actingAs($user)->patch(route('characters.update', ['id' => $character->id]), [
            'name' => 'Grandpa Joseph',
            'ageGroup' => 'child',
        ])->assertSessionHasNoErrors();

        $this->assertSame('child', $character->refresh()->age_group);
    }

    public function test_an_unknown_age_group_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('characters.store'), [
            'name' => 'Grandpa Joe',
            'ageGroup' => 'teenager',
        ])->assertSessionHasErrors('ageGroup');

        $this->assertSame(0, Character::query()->count());
    }
}
