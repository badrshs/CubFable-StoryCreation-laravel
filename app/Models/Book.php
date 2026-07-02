<?php

namespace App\Models;

use App\Enums\BookStatus;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property int $template_id
 * @property string $child_name
 * @property string $age_range
 * @property string $theme
 * @property string $subject
 * @property string $life_lesson
 * @property string $art_style
 * @property string $font
 * @property string $language
 * @property BookStatus $status
 * @property string|null $cover_image_path
 * @property string|null $cover_status
 * @property string|null $hero_sheet_path
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $cover_image_url
 */
#[Fillable([
    'user_id',
    'template_id',
    'child_name',
    'age_range',
    'theme',
    'subject',
    'life_lesson',
    'art_style',
    'font',
    'language',
    'status',
    'cover_image_path',
    'cover_status',
    'hero_sheet_path',
    'paid_at',
])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'language' => 'en',
        'status' => BookStatus::Draft->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * @return HasMany<Page, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('page_number');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<AiUsage, $this>
     */
    public function aiUsage(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    /**
     * @return BelongsToMany<Character, $this>
     */
    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(Character::class, 'book_characters')
            ->withPivot(['is_main', 'sort_order'])
            ->orderByPivot('is_main', 'desc')
            ->orderByPivot('sort_order');
    }

    /**
     * The public URL of the generated cover image, if one exists.
     *
     * @return Attribute<string|null, never>
     */
    protected function coverImageUrl(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->cover_image_path === null
            ? null
            : Storage::disk('public')->url($this->cover_image_path));
    }
}
