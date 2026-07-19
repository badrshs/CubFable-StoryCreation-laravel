<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Books the caller may open in the reader and act on: their own, or any book
 * when the caller is an admin (support access). Anyone else's book stays a
 * 404, never a 403, so a foreign id is indistinguishable from a missing one.
 */
trait FindsAccessibleBooks
{
    /**
     * @param  list<string>  $with
     */
    private function accessibleBook(Request $request, int $id, array $with = []): Book
    {
        return Book::query()
            ->with($with)
            ->when(
                ! $request->user()->is_admin,
                fn (Builder $query) => $query->where('user_id', $request->user()->id),
            )
            ->findOrFail($id);
    }
}
