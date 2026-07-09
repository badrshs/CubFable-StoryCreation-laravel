<?php

namespace App\Jobs;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Models\Book;
use App\Services\StoryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateStorybookJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 3600;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $bookId)
    {
        $this->onQueue('books');
    }

    /**
     * Execute the job.
     */
    public function handle(StoryGenerator $generator): void
    {
        $book = Book::query()->find($this->bookId);

        if ($book === null || $book->status !== BookStatus::Pending) {
            return;
        }

        Context::add('book_id', $book->id);
        Log::info('Generation run started.', [
            'image_provider' => (string) config('cubfable.ai.image_provider'),
            'art_style' => $book->art_style,
            'language' => $book->language,
        ]);

        $generator->generateStorybook($book);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("GenerateStorybookJob failed for book {$this->bookId}: ".($exception?->getMessage() ?? 'unknown'));

        $book = Book::query()->find($this->bookId);

        if ($book === null) {
            return;
        }

        $book->update(['status' => BookStatus::Failed]);
        $book->pages()->where('status', PageStatus::Generating)->update(['status' => PageStatus::Failed]);
    }
}
