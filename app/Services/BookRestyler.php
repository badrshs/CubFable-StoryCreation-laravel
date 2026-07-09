<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Jobs\GenerateStorybookJob;
use App\Models\Book;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-render every image of a finished book in a different art style. The
 * story is kept (only images are paid for again): the book returns to Pending
 * and the normal generation pipeline regenerates the sheet, cover and pages,
 * which also makes an interrupted restyle resumable. The current images are
 * archived as restorable versions, never deleted. Shared by the reader's
 * restyle dialog and the admin area.
 */
class BookRestyler
{
    public function __construct(private ImageVersionArchiver $versions) {}

    public function restyle(Book $book, string $artStyle): void
    {
        Log::info("Restyle requested: {$book->art_style} -> {$artStyle}.", ['book_id' => $book->id]);
        $this->versions->capture($book);

        DB::transaction(function () use ($book, $artStyle): void {
            $book->update([
                'art_style' => $artStyle,
                'status' => BookStatus::Pending,
                'cover_image_path' => null,
                'hero_sheet_path' => null,
            ]);

            $book->pages()->update(['image_path' => null, 'status' => PageStatus::Generating]);
        });

        GenerateStorybookJob::dispatch($book->id);
    }
}
