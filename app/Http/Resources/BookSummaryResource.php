<?php

namespace App\Http\Resources;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The gallery/library shape: book props plus page progress counts when the
 * query eager-loaded them via withCount (pages_total / pages_done).
 *
 * @mixin Book
 */
class BookSummaryResource extends JsonResource
{
    use MapsCubfableProps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $props = $this->bookProps($this->resource);

        if ($this->resource->getAttribute('pages_total') !== null) {
            $props['pagesTotal'] = (int) $this->resource->getAttribute('pages_total');
            $props['pagesDone'] = (int) $this->resource->getAttribute('pages_done');
        }

        return $props;
    }
}
