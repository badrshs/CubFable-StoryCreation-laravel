<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Support\MediaDisk;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $book_id
 * @property int $page_number
 * @property string $text
 * @property string|null $scene
 * @property string|null $image_path
 * @property string|null $image_prompt
 * @property array<string, string>|null $art_direction
 * @property PageStatus $status
 * @property Carbon|null $flagged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $image_url
 */
#[Fillable([
    'book_id',
    'page_number',
    'text',
    'scene',
    'image_path',
    'image_prompt',
    'art_direction',
    'status',
    'flagged_at',
])]
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PageStatus::Pending->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PageStatus::class,
            'art_direction' => 'array',
            'flagged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * The public URL of the page illustration, if one exists.
     *
     * @return Attribute<string|null, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->image_path === null
            ? null
            : MediaDisk::public()->url($this->image_path));
    }
}
