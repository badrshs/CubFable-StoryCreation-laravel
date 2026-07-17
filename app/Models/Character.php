<?php

namespace App\Models;

use App\Support\MediaDisk;
use Database\Factories\CharacterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $role
 * @property string|null $age_group
 * @property string|null $description
 * @property string|null $photo_path
 * @property string|null $appearance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $photo_url
 */
#[Fillable([
    'user_id',
    'name',
    'role',
    'age_group',
    'description',
    'photo_path',
    'appearance',
])]
class Character extends Model
{
    /** @use HasFactory<CharacterFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Book, $this>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_characters')
            ->withPivot(['is_main', 'sort_order']);
    }

    /**
     * A short-lived signed URL to the reference photo, if one exists. The
     * photo lives on the private disk, so it is never served from a public
     * URL.
     *
     * @return Attribute<string|null, never>
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->photo_path === null
            ? null
            : MediaDisk::temporaryUrl($this->photo_path));
    }
}
