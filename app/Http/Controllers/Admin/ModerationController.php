<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ImagePrompt;
use App\Models\Page;
use App\Services\AI\Replicate\ReplicateModelCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue for images every engine refused on content grounds:
 * flagged covers and pages, each with the full attempt timeline (round,
 * engine, prompt variant, exact provider error), so the admin can see what
 * happened, reword the scene, retry on any engine, or dismiss the flag.
 */
class ModerationController extends Controller
{
    public function index(ReplicateModelCatalog $catalog): Response
    {
        $items = [];

        foreach (Book::query()->whereNotNull('cover_flagged_at')->with('user')->orderByDesc('cover_flagged_at')->get() as $book) {
            $items[] = [
                'type' => 'cover',
                'bookId' => $book->id,
                'childName' => $book->child_name,
                'userEmail' => $book->user->email ?? '',
                'pageId' => null,
                'pageNumber' => null,
                'flaggedAt' => $book->cover_flagged_at?->toIso8601String(),
                'imageUrl' => $book->cover_image_url,
                'sceneAction' => null,
                'sceneDetail' => null,
                'attempts' => $this->attempts($book->id, 'cover', null),
            ];
        }

        foreach (Page::query()->whereNotNull('flagged_at')->with('book.user')->orderByDesc('flagged_at')->get() as $page) {
            $items[] = [
                'type' => 'page',
                'bookId' => $page->book_id,
                'childName' => $page->book->child_name ?? '',
                'userEmail' => $page->book->user->email ?? '',
                'pageId' => $page->id,
                'pageNumber' => $page->page_number,
                'flaggedAt' => $page->flagged_at?->toIso8601String(),
                'imageUrl' => $page->image_url,
                'sceneAction' => $page->art_direction['action'] ?? $page->scene,
                'sceneDetail' => $page->art_direction['detail'] ?? '',
                'attempts' => $this->attempts($page->book_id, 'page', $page->id),
            ];
        }

        return Inertia::render('admin/moderation', [
            'items' => $items,
            'engines' => [
                'providers' => ['openai', 'gemini', 'openrouter', 'flow', 'grok', 'piapi', 'replicate'],
                'replicate' => $catalog->options(),
            ],
        ]);
    }

    public function dismissPage(int $id): RedirectResponse
    {
        Page::query()->whereNotNull('flagged_at')->findOrFail($id)->update(['flagged_at' => null]);

        return back();
    }

    public function dismissCover(int $id): RedirectResponse
    {
        Book::query()->whereNotNull('cover_flagged_at')->findOrFail($id)->update(['cover_flagged_at' => null]);

        return back();
    }

    /**
     * Reword a flagged page's visual action/detail (the text the image
     * prompt is built from), so the next retry runs with clean wording.
     */
    public function updateScene(Request $request, int $id): RedirectResponse
    {
        $page = Page::query()->whereNotNull('flagged_at')->findOrFail($id);

        $validated = $request->validate([
            'action' => ['required', 'string', 'max:500'],
            'detail' => ['nullable', 'string', 'max:300'],
        ]);

        $action = trim($validated['action']);
        $detail = trim((string) ($validated['detail'] ?? ''));

        $artDirection = array_filter([
            ...($page->art_direction ?? []),
            'action' => $action,
            'detail' => $detail,
        ], fn (string $value): bool => $value !== '');

        $page->update([
            'art_direction' => $artDirection,
            'scene' => trim($action.' '.$detail),
        ]);

        return back();
    }

    /**
     * The attempt timeline for one flagged item, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function attempts(int $bookId, string $purpose, ?int $pageId): array
    {
        return ImagePrompt::query()
            ->where('book_id', $bookId)
            ->where('purpose', $purpose)
            ->when($pageId !== null, fn ($query) => $query->where('page_id', $pageId))
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->map(fn (ImagePrompt $row): array => [
                'id' => $row->id,
                'attempt' => $row->attempt,
                'round' => $row->round,
                'variant' => $row->variant,
                'provider' => $row->provider,
                'model' => $row->model,
                'accepted' => $row->accepted,
                'error' => $row->error,
                'prompt' => $row->prompt,
                'createdAt' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
