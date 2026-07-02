<?php

namespace App\Jobs;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\Page;
use App\Services\StoryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegeneratePageJob implements ShouldQueue
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
     * Create a new job instance.
     */
    public function __construct(public int $pageId) {}

    /**
     * Execute the job.
     */
    public function handle(StoryGenerator $generator): void
    {
        $page = Page::query()->find($this->pageId);

        if ($page === null) {
            return;
        }

        $book = Book::query()->find($page->book_id);

        if ($book === null) {
            return;
        }

        $generator->regeneratePageIllustration($page, $book);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("RegeneratePageJob failed for page {$this->pageId}: ".($exception?->getMessage() ?? 'unknown'));

        Page::query()->whereKey($this->pageId)->update(['status' => PageStatus::Failed]);
    }
}
