<?php

namespace App\Services;

use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stores book and character imagery on the public disk. Layout:
 *   books/{bookId}/cover-{rand}.png
 *   books/{bookId}/pages/{n}-{rand}.png
 *   characters/{characterId}/photo-{rand}.jpg
 */
class BookImageStorage
{
    /**
     * Generous enough for original phone photos when the photo-quality
     * setting is 'original' (optimized uploads stay well under this).
     */
    private const MAX_DECODED_BYTES = 12 * 1024 * 1024;

    /**
     * Decode a user-supplied base64 image data URL, validate it, and store it
     * in the given directory. Returns the stored path.
     */
    public function storeDataUrl(string $dataUrl, string $directory): string
    {
        if (preg_match('/^data:image\/(png|jpe?g|webp);base64,(.+)$/i', $dataUrl, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid image data URL.');
        }

        $declared = strtolower($matches[1]);
        $declaredMime = 'image/'.($declared === 'jpg' ? 'jpeg' : $declared);

        $bytes = base64_decode($matches[2], true);

        if ($bytes === false || $bytes === '') {
            throw new InvalidArgumentException('Invalid base64 image data.');
        }

        if (strlen($bytes) > self::MAX_DECODED_BYTES) {
            throw new InvalidArgumentException('Image exceeds the 12MB size limit.');
        }

        $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($detectedMime !== $declaredMime) {
            throw new InvalidArgumentException('Image content does not match its declared type.');
        }

        $extension = match ($declaredMime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $path = trim($directory, '/').'/photo-'.Str::lower(Str::random(8)).'.'.$extension;

        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /**
     * Store AI-generated image bytes at the given path. Returns the path.
     */
    public function storeGenerated(string $bytes, string $path): string
    {
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    /**
     * Delete a previously stored file, tolerating null/blank paths.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * Delete a whole directory of stored images (e.g. everything a deleted
     * book owned).
     */
    public function deleteDirectory(string $directory): void
    {
        Storage::disk('public')->deleteDirectory($directory);
    }
}
