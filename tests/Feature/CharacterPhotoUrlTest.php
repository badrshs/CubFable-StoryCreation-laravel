<?php

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CharacterPhotoUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_url_is_null_without_a_photo(): void
    {
        $character = Character::factory()->create(['photo_path' => null]);

        $this->assertNull($character->photo_url);
    }

    public function test_photo_url_is_a_short_lived_signed_url_from_the_private_disk(): void
    {
        // The local disk is served, so it issues real signed temporary URLs.
        config()->set('cubfable.media.private_disk', 'local');
        Storage::fake('local');

        $character = Character::factory()->withPhoto()->create();

        $url = $character->photo_url;

        $this->assertNotNull($url);
        // A time-limited URL, not a permanent public one.
        $this->assertStringContainsString('expiration', $url);
    }
}
