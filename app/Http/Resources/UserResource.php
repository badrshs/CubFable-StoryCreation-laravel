<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, email: string, emailVerified: bool, createdAt: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'emailVerified' => $this->email_verified_at !== null,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
