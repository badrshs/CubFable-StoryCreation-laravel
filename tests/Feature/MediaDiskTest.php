<?php

namespace Tests\Feature;

use App\Support\MediaDisk;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaDiskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Distinct disks so we can prove which one a path resolves to.
        config()->set('cubfable.media.disk', 'public');
        config()->set('cubfable.media.private_disk', 'local');

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_character_paths_resolve_to_the_private_disk(): void
    {
        MediaDisk::for('characters/7/photo-abc.jpg')->put('characters/7/photo-abc.jpg', 'x');

        Storage::disk('local')->assertExists('characters/7/photo-abc.jpg');
        Storage::disk('public')->assertMissing('characters/7/photo-abc.jpg');
    }

    public function test_book_paths_resolve_to_the_public_disk(): void
    {
        MediaDisk::for('books/3/pages/1-abc.png')->put('books/3/pages/1-abc.png', 'x');

        Storage::disk('public')->assertExists('books/3/pages/1-abc.png');
        Storage::disk('local')->assertMissing('books/3/pages/1-abc.png');
    }

    public function test_public_and_private_resolve_the_configured_disks(): void
    {
        MediaDisk::public()->put('books/1/cover.png', 'x');
        MediaDisk::private()->put('characters/1/photo.jpg', 'y');

        Storage::disk('public')->assertExists('books/1/cover.png');
        Storage::disk('local')->assertExists('characters/1/photo.jpg');
    }
}
