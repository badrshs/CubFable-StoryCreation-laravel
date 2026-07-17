<?php

namespace Tests\Feature;

use App\Services\BookImageStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookImageStorageTest extends TestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cubfable.media.disk', 'public');
        config()->set('cubfable.media.private_disk', 'local');

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_uploaded_photo_goes_on_the_private_disk(): void
    {
        $path = app(BookImageStorage::class)->storeDataUrl(
            'data:image/png;base64,'.self::PNG_BASE64,
            'characters/9',
        );

        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_generated_art_goes_on_the_public_disk(): void
    {
        $path = 'books/4/cover-abc.png';

        app(BookImageStorage::class)->storeGenerated('rawbytes', $path);

        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_targets_the_disk_that_owns_the_path(): void
    {
        $storage = app(BookImageStorage::class);
        Storage::disk('local')->put('characters/9/photo-abc.jpg', 'x');
        Storage::disk('public')->put('books/4/cover-abc.png', 'y');

        $storage->delete('characters/9/photo-abc.jpg');
        $storage->delete('books/4/cover-abc.png');

        Storage::disk('local')->assertMissing('characters/9/photo-abc.jpg');
        Storage::disk('public')->assertMissing('books/4/cover-abc.png');
    }
}
