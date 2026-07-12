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
        $props = $this->templateProps($this->resource);

        // Template covers are stored as site-relative paths, which the web
        // serves same-origin. A native app needs an absolute URL, built from
        // the host this request actually arrived on.
        $cover = $props['coverImageUrl'];

        if (is_string($cover) && str_starts_with($cover, '/')) {
            $props['coverImageUrl'] = url($cover);
        }

        return $props;
    }
}
