<?php

namespace App\Http\Controllers;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Http\Requests\UpdatePageRequest;
use App\Jobs\RegeneratePageJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    /**
     * Edit the text of a page. Pages have no owner column of their own, so
     * ownership is enforced through the parent book: a page under a book the
     * caller does not own is treated as not found (404).
     */
    public function update(UpdatePageRequest $request, int $id, int $pageId): RedirectResponse
    {
        $book = $request->user()->books()->findOrFail($id);
        $page = $book->pages()->findOrFail($pageId);

        $page->update(['text' => $request->validated()['text']]);

        return back();
    }

    /**
     * Queue an illustration regeneration for a page of a paid book.
     */
    public function regenerate(Request $request, int $id, int $pageId): RedirectResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        abort_if($book->status === BookStatus::Draft, 402);

        $page = $book->pages()->findOrFail($pageId);

        $page->update(['status' => PageStatus::Generating]);

        RegeneratePageJob::dispatch($page->id);

        return back();
    }
}
