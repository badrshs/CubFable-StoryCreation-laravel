<?php

namespace App\Http\Resources;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The reader shape: the book plus its ordered pages and cast, matching the
 * bookWithPagesProps payload the web reader receives.
 *
 * @mixin Book
 */
class BookResource extends JsonResource
{
    use MapsCubfableProps;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->bookWithPagesProps($this->resource);
    }
}
