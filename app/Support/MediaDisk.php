<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves the filesystem disks that hold user media.
 *
 * Two stores back the app's media (see config/cubfable.php):
 *   - the public/CDN disk for generated art (books/{id}/... paths)
 *   - the private disk for uploaded child photos (characters/{id}/... paths)
 *
 * Generic consumers that only have a stored path (AI references, the PDF
 * builder) use for() to pick the right disk from the path's layout prefix.
 */
class MediaDisk
{
    /**
     * The public/CDN disk holding generated covers and page art.
     */
    public static function public(): Filesystem
    {
        return Storage::disk(config('cubfable.media.disk'));
    }

    /**
     * The private disk holding uploaded reference photos.
     */
    public static function private(): Filesystem
    {
        return Storage::disk(config('cubfable.media.private_disk'));
    }

    /**
     * Resolve the disk a stored media path lives on from its layout prefix.
     * Only uploaded photos live under characters/; everything else is
     * generated art on the public disk.
     */
    public static function for(string $path): Filesystem
    {
        return str_starts_with($path, 'characters/')
            ? self::private()
            : self::public();
    }

    /**
     * A short-lived signed URL for a private media path.
     */
    public static function temporaryUrl(string $path): string
    {
        $ttl = (int) config('cubfable.media.signed_url_ttl', 30);

        return self::private()->temporaryUrl($path, Carbon::now()->addMinutes($ttl));
    }
}
