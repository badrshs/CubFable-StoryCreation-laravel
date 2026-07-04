<?php

namespace App\Console\Commands;

use App\Enums\BookStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('cubfable:resume-book {book : The id of the stuck or failed book}')]
#[Description('Requeue generation for a paid book stuck in generating (dead worker) or failed; already generated images are kept')]
class ResumeBook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $book = Book::query()->find($this->argument('book'));

        if ($book === null) {
            $this->error('Book not found.');

            return self::FAILURE;
        }

        if (! in_array($book->status, [BookStatus::Pending, BookStatus::Generating, BookStatus::Failed], true)) {
            $this->error("Book {$book->id} is {$book->status->value}; only pending, generating or failed books can be resumed.");

            return self::FAILURE;
        }

        // Drop any stale queue entry for this book (e.g. a job still marked
        // reserved by a worker that died) so it cannot fire a duplicate run
        // or a late MaxAttempts failure over the resumed one. The bookId
        // marker appears quote-escaped inside the JSON payload, so the
        // precise match happens in PHP rather than in SQL.
        $marker = '"bookId";i:'.$book->id.';';

        $stale = DB::table('jobs')
            ->where('payload', 'like', '%GenerateStorybookJob%')
            ->get(['id', 'payload'])
            ->filter(fn (object $row): bool => str_contains(stripslashes((string) $row->payload), $marker))
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            DB::table('jobs')->whereIn('id', $stale)->delete();
            $this->info("Removed {$stale->count()} stale queue entr".($stale->count() === 1 ? 'y' : 'ies').'.');
        }

        $book->update(['status' => BookStatus::Pending]);

        GenerateStorybookJob::dispatch($book->id);

        $done = $book->pages()->whereNotNull('image_path')->count();
        $this->info("Book {$book->id} requeued; {$done} existing page image(s) will be kept. Make sure a queue worker is running.");

        return self::SUCCESS;
    }
}
