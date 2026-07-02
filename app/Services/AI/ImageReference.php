<?php

namespace App\Services\AI;

use finfo;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A reference photo passed to an image model (e.g. a character's face), stored
 * as a path on the public disk, with an optional label naming who it is.
 */
final readonly class ImageReference
{
    public function __construct(
        public string $path,
        public ?string $label = null,
    ) {}

    /**
     * Read the referenced file and build a base64 data URL with detected mime.
     */
    public function dataUrl(): string
    {
        $bytes = Storage::disk('public')->get($this->path);

        if ($bytes === null) {
            throw new RuntimeException("Reference image [{$this->path}] could not be read.");
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($mime === false || $mime === '') {
            $mime = 'application/octet-stream';
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
