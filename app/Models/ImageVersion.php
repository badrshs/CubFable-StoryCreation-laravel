<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One generated image file for a book slot (cover, character sheet, or a
 * page). Generation never deletes the replaced file anymore; the pointer
 * columns on Book/Page mark the active version and the admin can restore
 * any older one.
 *
 * @property int $id
 * @property int $book_id
 * @property ?int $page_id
 * @property ?int $page_number
 * @property string $slot
 * @property string $path
 * @property ?string $prompt
 * @property ?string $engine_provider
 * @property ?string $engine_model
 * @property ?Carbon $created_at
 */
class ImageVersion extends Model
{
    protected $fillable = [
        'book_id',
        'page_id',
        'page_number',
        'slot',
        'path',
        'prompt',
        'engine_provider',
        'engine_model',
    ];

    /**
     * A short human label of the engine that produced this version, e.g.
     * "replicate - black-forest-labs/flux-kontext-pro". Empty for versions
     * that predate engine tracking.
     */
    public function engineLabel(): string
    {
        return trim(implode(' - ', array_filter([$this->engine_provider, $this->engine_model])));
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function url(): ?string
    {
        return MediaDisk::public()->exists($this->path)
            ? MediaDisk::public()->url($this->path)
            : null;
    }
}
