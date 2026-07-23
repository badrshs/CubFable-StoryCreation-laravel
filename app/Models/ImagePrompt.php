<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One image-generation attempt: the exact prompt sent to the provider for a
 * character sheet, cover, or page, which safety-ladder variant it was, and
 * whether the provider accepted it. Every attempt is kept, so the original
 * prompt survives even when a safety rewrite was needed.
 *
 * @property int $id
 * @property int $book_id
 * @property int|null $page_id
 * @property string $purpose
 * @property int $attempt
 * @property int $round
 * @property string $variant
 * @property string|null $provider
 * @property string|null $model
 * @property string $prompt
 * @property array<int, array{path: string, label: string|null}>|null $references
 * @property bool $accepted
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'book_id',
    'page_id',
    'purpose',
    'attempt',
    'round',
    'variant',
    'provider',
    'model',
    'prompt',
    'references',
    'accepted',
    'error',
])]
class ImagePrompt extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted' => 'boolean',
            'references' => 'array',
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
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
