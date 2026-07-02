<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ImagePrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Development-only inspection endpoint: every image-generation prompt
 * attempt journaled for one of the caller's books, as plain JSON. Returns
 * 404 in production, exactly like a route that does not exist.
 */
class DebugPromptController extends Controller
{
    public function __invoke(Request $request, int $id): JsonResponse
    {
        abort_if(app()->environment('production'), 404);

        /** @var Book $book */
        $book = $request->user()->books()->findOrFail($id);

        $pageNumbers = $book->pages()->pluck('page_number', 'id');

        $prompts = ImagePrompt::query()
            ->where('book_id', $book->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'bookId' => $book->id,
            'childName' => $book->child_name,
            'count' => $prompts->count(),
            'prompts' => $prompts->map(fn (ImagePrompt $prompt): array => [
                'id' => $prompt->id,
                'purpose' => $prompt->purpose,
                'pageId' => $prompt->page_id,
                'pageNumber' => $prompt->page_id === null ? null : ($pageNumbers[$prompt->page_id] ?? null),
                'attempt' => $prompt->attempt,
                'variant' => $prompt->variant,
                'accepted' => $prompt->accepted,
                'prompt' => $prompt->prompt,
                'createdAt' => $prompt->created_at?->toISOString(),
            ])->all(),
        ]);
    }
}
