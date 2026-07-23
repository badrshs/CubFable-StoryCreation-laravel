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

/**
 * Regenerate the main character's portrait from a book page. Updates only the
 * character's saved portrait (shared by every book that uses that character);
 * the book itself is never touched. Optional engine and art-style overrides
 * apply to this run only.
 */
class RegenerateCharacterPortraitJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $bookId,
        public ?string $imageProvider = null,
        public ?string $imageModel = null,
        public ?string $artStyle = null,
        public ?int $characterId = null,
    ) {}

    public function handle(StoryGenerator $generator, BookStopSignal $stopSignal): void
    {
        $book = Book::query()->find($this->bookId);

        if ($book === null) {
            return;
        }

        // Starting a regeneration is intentional: a leftover stop flag must
        // not kill it.
        $stopSignal->clear($book->id);

        Context::add('book_id', $book->id);
        Log::info('Regenerating the character portrait.');
        EngineOverride::apply($this->imageProvider, $this->imageModel);
        StyleOverride::apply($this->artStyle);

        $generator->regenerateCharacterPortrait($book, $this->characterId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error("RegenerateCharacterPortraitJob failed for book {$this->bookId}: ".($exception?->getMessage() ?? 'unknown'));
    }
}
