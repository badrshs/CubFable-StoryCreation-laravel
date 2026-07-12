<?php

namespace App\Http\Resources;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    use MapsCubfableProps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->templateProps($this->resource);
    }
}
