<?php

namespace App\Models;

use Database\Factories\UserIpFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $ip
 * @property bool|null $is_vpn
 * @property bool|null $is_datacenter
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
#[Fillable(['user_id', 'ip', 'is_vpn', 'is_datacenter', 'first_seen_at', 'last_seen_at'])]
class UserIp extends Model
{
    /** @use HasFactory<UserIpFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_vpn' => 'boolean',
            'is_datacenter' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
