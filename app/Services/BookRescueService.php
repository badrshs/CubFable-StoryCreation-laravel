<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Requeue generation for a stuck or failed book (dead worker),
 * keeping every image that already exists. Shared by the cubfable:resume-book
 * command and the admin area.
 */
class BookRescueService
{
    /**
     * @return int how many stale queue entries were removed
     *
     * @throws InvalidArgumentException when the book's status is not resumable
     */
    public function resume(Book $book): int
    {
        if (! in_array($book->status, [BookStatus::Pending, BookStatus::Generating, BookStatus::Failed], true)) {
            throw new InvalidArgumentException(
                "Book {$book->id} is {$book->status->value}; only pending, generating or failed books can be resumed.",
            );
        }

        $stale = $this->clearStaleJobs($book);

        $book->update(['status' => BookStatus::Pending]);

        GenerateStorybookJob::dispatch($book->id);

        return $stale;
    }

    /**
     * Drop any stale queue entry for this book (e.g. a job still marked
     * reserved by a worker that died) so it cannot fire a duplicate run or a
     * late MaxAttempts failure over the resumed one. The bookId marker appears
     * quote-escaped inside the JSON payload, so the precise match happens in
     * PHP rather than in SQL. Also used by BookRestarter before a full re-run.
     */
    public function clearStaleJobs(Book $book): int
    {
        $marker = '"bookId";i:'.$book->id.';';

        $stale = DB::table('jobs')
            ->where('payload', 'like', '%GenerateStorybookJob%')
            ->get(['id', 'payload'])
            ->filter(fn (object $row): bool => str_contains(stripslashes((string) $row->payload), $marker))
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            DB::table('jobs')->whereIn('id', $stale)->delete();
        }

        return $stale->count();
    }
}
