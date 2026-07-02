<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $description
 * @property string $theme
 * @property int $age_min
 * @property int $age_max
 * @property string $cover_image_url
 * @property int $page_count
 * @property array<int, string> $life_lessons
 * @property array<int, string> $art_styles
 * @property array<int, string> $subjects
 * @property array<int, string> $fonts
 * @property string $image_prompt
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title',
    'description',
    'theme',
    'age_min',
    'age_max',
    'cover_image_url',
    'page_count',
    'life_lessons',
    'art_styles',
    'subjects',
    'fonts',
    'image_prompt',
])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'life_lessons' => 'array',
            'art_styles' => 'array',
            'subjects' => 'array',
            'fonts' => 'array',
        ];
    }

    /**
     * @return HasMany<Book, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
