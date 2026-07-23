<?php

namespace App\Models;

use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A character's canonical stylized rendition in one art style: the
 * photo-to-illustration jump happens once per character and style, and every
 * book in that style anchors its images to this portrait instead of the raw
 * photo.
 *
 * @property int $id
 * @property int $character_id
 * @property string $art_style
 * @property string $path
 * @property string|null $prompt
 * @property string|null $engine_provider
 * @property string|null $engine_model
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $url
 */
#[Fillable(['character_id', 'art_style', 'path', 'prompt', 'engine_provider', 'engine_model'])]
class CharacterPortrait extends Model
{
    /**
     * @return BelongsTo<Character, $this>
     */
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    /**
     * Public URL of the portrait file (generated art lives on the CDN disk),
     * or null when the file no longer exists.
     *
     * @return Attribute<string|null, never>
     */
    protected function url(): Attribute
    {
        return Attribute::make(get: fn (): ?string => MediaDisk::public()->exists($this->path)
            ? MediaDisk::public()->url($this->path)
            : null);
    }
}
