<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ImageVersion;
use App\Models\Page;

/**
 * Record a book's CURRENT images as restorable versions. New generations
 * record themselves, but books that predate version tracking only have
 * pointer columns; capturing before a restyle/restart makes those images
 * restorable instead of orphaned.
 */
class ImageVersionArchiver
{
    public function capture(Book $book): void
    {
        $this->record($book, 'cover', null, null, $book->cover_image_path, $book->cover_prompt);
        $this->record($book, 'sheet', null, null, $book->hero_sheet_path, $book->hero_sheet_prompt);

        foreach ($book->pages()->get() as $page) {
            $this->record($book, 'page', $page->id, $page->page_number, $page->image_path, $page->image_prompt);
        }
    }

    /**
     * Capture just the cover, for a single-slot regeneration on a book whose
     * current cover predates version tracking.
     */
    public function captureCover(Book $book): void
    {
        $this->record($book, 'cover', null, null, $book->cover_image_path, $book->cover_prompt);
    }

    /**
     * Capture just one page's image, for the same reason.
     */
    public function capturePage(Book $book, Page $page): void
    {
        $this->record($book, 'page', $page->id, $page->page_number, $page->image_path, $page->image_prompt);
    }

    private function record(Book $book, string $slot, ?int $pageId, ?int $pageNumber, ?string $path, ?string $prompt): void
    {
        if ($path === null || $path === '') {
            return;
        }

        ImageVersion::query()->firstOrCreate(
            ['book_id' => $book->id, 'slot' => $slot, 'path' => $path],
            ['page_id' => $pageId, 'page_number' => $pageNumber, 'prompt' => $prompt],
        );
    }
}
