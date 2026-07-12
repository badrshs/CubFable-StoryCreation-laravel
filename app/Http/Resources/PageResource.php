<?php

namespace App\Http\Resources;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Page
 */
class PageResource extends JsonResource
{
    use MapsCubfableProps;

    /**
     * @return array{id: int, pageNumber: int, text: string, imageUrl: string|null, status: string}
     */
    public function toArray(Request $request): array
    {
        return $this->pageProps($this->resource);
    }
}
