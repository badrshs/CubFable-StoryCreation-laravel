<?php

namespace App\Http\Resources;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Character
 */
class CharacterResource extends JsonResource
{
    use MapsCubfableProps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->characterProps($this->resource);
    }
}
