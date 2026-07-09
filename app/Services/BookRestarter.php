<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Restart a book from scratch, whatever state it is in: wipe the story, the
 * bible and every image pointer, return the book to Pending and requeue the
 * normal generation pipeline. The current images are archived as restorable
 * versions, never deleted. Admin-only - readers never lose a paid book's
 * content; the keep-progress variant is BookRescueService::resume().
 */
class BookRestarter
{
    public function __construct(
        private BookRescueService $rescue,
        private ImageVersionArchiver $versions,
    ) {}

    public function restart(Book $book): void
    {
        Log::info("Full restart requested (was {$book->status->value}); story and images will regenerate.", ['book_id' => $book->id]);
        $this->versions->capture($book);

        DB::transaction(function () use ($book): void {
            $book->pages()->delete();

            $book->update([
                'status' => BookStatus::Pending,
                'story_bible' => null,
                'cover_image_path' => null,
                'cover_prompt' => null,
                'cover_status' => null,
                'hero_sheet_path' => null,
                'hero_sheet_prompt' => null,
            ]);
        });

        // A book stuck in Generating may still own a reserved queue entry
        // from a dead worker; clear it so the restart cannot double-run.
        $this->rescue->clearStaleJobs($book);

        GenerateStorybookJob::dispatch($book->id);
    }
}
