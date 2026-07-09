<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\BookRescueService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('cubfable:resume-book {book : The id of the stuck or failed book}')]
#[Description('Requeue generation for a paid book stuck in generating (dead worker) or failed; already generated images are kept')]
class ResumeBook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BookRescueService $rescue): int
    {
        $book = Book::query()->find($this->argument('book'));

        if ($book === null) {
            $this->error('Book not found.');

            return self::FAILURE;
        }

        try {
            $stale = $rescue->resume($book);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($stale > 0) {
            $this->info("Removed {$stale} stale queue entr".($stale === 1 ? 'y' : 'ies').'.');
        }

        $done = $book->pages()->whereNotNull('image_path')->count();
        $this->info("Book {$book->id} requeued; {$done} existing page image(s) will be kept. Make sure a queue worker is running.");

        return self::SUCCESS;
    }
}
