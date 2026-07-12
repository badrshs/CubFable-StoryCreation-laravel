<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ArtStyle;
use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Http\Resources\BookResource;
use App\Http\Resources\BookSummaryResource;
use App\Jobs\RegenerateCoverJob;
use App\Models\Book;
use App\Services\BookImageStorage;
use App\Services\BookRestyler;
use App\Services\DraftBookManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    /**
     * The user's library, newest books first, with page progress counts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $books = $request->user()->books()
            ->withCount([
                'pages as pages_total',
                'pages as pages_done' => fn ($query) => $query->where('status', PageStatus::Complete),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return BookSummaryResource::collection($books);
    }

    /**
     * Create a draft book with its cast. The client then takes the draft to
     * the in-app purchase flow; generation starts only after payment.
     */
    public function store(StoreBookRequest $request, DraftBookManager $drafts): JsonResponse
    {
        $book = $drafts->createDraft($request->user(), $request->validated());

        $book->load(['pages', 'characters']);

        return BookResource::make($book)->response($request)->setStatusCode(201);
    }

    /**
     * The reader payload. A book owned by someone else is indistinguishable
     * from one that does not exist (404, never 403).
     */
    public function show(Request $request, int $id): BookResource
    {
        $book = $request->user()->books()
            ->with(['pages', 'characters'])
            ->findOrFail($id);

        return BookResource::make($book);
    }

    /**
     * Apply wizard changes to an unpaid draft. Paid books are locked.
     */
    public function update(UpdateBookRequest $request, DraftBookManager $drafts, int $id): BookResource|JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        if ($book->status !== BookStatus::Draft) {
            return $this->bookNotEditable();
        }

        $drafts->updateDraft($request->user(), $book, $request->validated());

        $book->refresh()->load(['pages', 'characters']);

        return BookResource::make($book);
    }

    /**
     * Remove an unpaid draft. Paid books are keepsakes and cannot be deleted.
     */
    public function destroy(Request $request, BookImageStorage $images, int $id): Response|JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        if ($book->status !== BookStatus::Draft) {
            return $this->bookNotEditable();
        }

        $book->delete();

        $images->deleteDirectory("books/{$book->id}");

        return response()->noContent();
    }

    /**
     * Queue a cover regeneration for a paid book.
     */
    public function regenerateCover(Request $request, int $id): JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        if ($book->status === BookStatus::Draft) {
            return $this->paymentRequired();
        }

        $book->update(['cover_status' => 'generating']);

        RegenerateCoverJob::dispatch($book->id);

        return $this->acceptedStatus($book);
    }

    /**
     * Re-render every image of a finished book in a different art style. The
     * story is kept; the book returns to the normal generation pipeline.
     */
    public function restyle(Request $request, BookRestyler $restyler, int $id): JsonResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        if ($book->status === BookStatus::Draft) {
            return $this->paymentRequired();
        }

        if (! in_array($book->status, [BookStatus::Complete, BookStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'artStyle' => 'This book is still generating; try again when it finishes.',
            ]);
        }

        $validated = $request->validate([
            'artStyle' => ['required', Rule::enum(ArtStyle::class)],
        ]);

        $restyler->restyle($book, $validated['artStyle']);

        return $this->acceptedStatus($book->refresh());
    }

    private function bookNotEditable(): JsonResponse
    {
        return response()->json([
            'message' => __('This book is no longer editable.'),
            'code' => 'book_not_editable',
        ], 409);
    }

    private function paymentRequired(): JsonResponse
    {
        return response()->json([
            'message' => __('This book has not been paid for yet.'),
            'code' => 'payment_required',
        ], 402);
    }

    private function acceptedStatus(Book $book): JsonResponse
    {
        return response()->json([
            'data' => [
                'status' => $book->status->value,
                'coverStatus' => $book->cover_status,
            ],
        ], 202);
    }
}
