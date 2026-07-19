<?php

namespace App\Models;

use Database\Factories\BenefitGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $benefit
 * @property string|null $device_id
 * @property string|null $fingerprint
 * @property string|null $ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'benefit', 'device_id', 'fingerprint', 'ip'])]
class BenefitGrant extends Model
{
    /** @use HasFactory<BenefitGrantFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
