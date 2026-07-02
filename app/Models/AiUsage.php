<?php

namespace App\Models;

use Database\Factories\AiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $book_id
 * @property string $kind
 * @property string $provider
 * @property string $model
 * @property int $prompt_tokens
 * @property int $output_tokens
 * @property int $total_tokens
 * @property float|null $cost_usd
 * @property bool $estimated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table('ai_usage')]
#[Fillable([
    'book_id',
    'kind',
    'provider',
    'model',
    'prompt_tokens',
    'output_tokens',
    'total_tokens',
    'cost_usd',
    'estimated',
])]
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'prompt_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'estimated' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_usd' => 'float',
            'estimated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
