<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PageStatus;
use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookStatusController extends Controller
{
    /**
     * Lightweight generation-progress payload, polled every few seconds by
     * the mobile progress screen and reader. Per-page statuses let finished
     * pages appear progressively without refetching the whole book.
     */
    public function __invoke(Request $request, int $id): JsonResponse
    {
        $book = $request->user()->books()
            ->select(['id', 'status', 'cover_status', 'cover_image_path'])
            ->findOrFail($id);

        $pages = $book->pages()
            ->orderBy('page_number')
            ->get(['id', 'book_id', 'page_number', 'status', 'image_path']);

        return response()
            ->json([
                'data' => [
                    'status' => $book->status->value,
                    'coverStatus' => $book->cover_status,
                    'coverImageUrl' => $book->cover_image_url,
                    'pagesTotal' => $pages->count(),
                    'pagesDone' => $pages->filter(fn (Page $page): bool => $page->status === PageStatus::Complete)->count(),
                    'pages' => $pages->map(fn (Page $page): array => [
                        'id' => $page->id,
                        'pageNumber' => $page->page_number,
                        'status' => $page->status->value,
                        'imageUrl' => $page->image_url,
                    ])->values()->all(),
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }
}
