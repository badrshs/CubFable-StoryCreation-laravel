<?php

namespace App\Jobs;

use App\Models\Book;
use App\Services\BookStopSignal;
use App\Services\StoryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegenerateCoverJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance. An optional engine override applies to this
     * run only (the worker boots fresh per job, so nothing leaks).
     */
    public function __construct(
        public int $bookId,
        public ?string $imageProvider = null,
        public ?string $imageModel = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(StoryGenerator $generator, BookStopSignal $stopSignal): void
    {
        $book = Book::query()->find($this->bookId);

        if ($book === null) {
            return;
        }

        // Starting a regeneration is an intentional act: a stop flag left
        // over from halting an earlier run (1 hour TTL) must not kill it.
        $stopSignal->clear($book->id);

        Context::add('book_id', $book->id);
        Log::info('Regenerating the cover.');
        EngineOverride::apply($this->imageProvider, $this->imageModel);

        $generator->regenerateCover($book);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("RegenerateCoverJob failed for book {$this->bookId}: ".($exception?->getMessage() ?? 'unknown'));

        Book::query()->whereKey($this->bookId)->update(['cover_status' => 'failed']);
    }
}
