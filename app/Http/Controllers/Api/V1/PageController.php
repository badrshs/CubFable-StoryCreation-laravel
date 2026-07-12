<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePageRequest;
use App\Http\Resources\PageResource;
use App\Jobs\RegeneratePageJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    /**
     * Edit the text of a page. Ownership is enforced through the parent book:
     * a page under a book the caller does not own is a 404.
     */
    public function update(UpdatePageRequest $request, int $id, int $pageId): PageResource
    {
        $book = $request->user()->books()->findOrFail($id);
        $page = $book->pages()->findOrFail($pageId);

        $page->update(['text' => $request->validated()['text']]);

        return PageResource::make($page);
    }

    /**
     * Queue an illustration regeneration for a page of a paid book.
     */
    public function regenerate(Request $request, int $id, int $pageId): JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        if ($book->status === BookStatus::Draft) {
            return response()->json([
                'message' => __('This book has not been paid for yet.'),
                'code' => 'payment_required',
            ], 402);
        }

        $page = $book->pages()->findOrFail($pageId);

        $page->update(['status' => PageStatus::Generating]);

        RegeneratePageJob::dispatch($page->id);

        return PageResource::make($page)->response($request)->setStatusCode(202);
    }
}
