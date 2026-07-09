<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ArtStyle;
use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RegenerateCoverJob;
use App\Jobs\RegeneratePageJob;
use App\Models\Book;
use App\Models\ImagePrompt;
use App\Models\ImageVersion;
use App\Models\Page;
use App\Services\BookImageStorage;
use App\Services\BookRescueService;
use App\Services\BookRestarter;
use App\Services\BookRestyler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BookController extends Controller
{
    /**
     * Every book in the system: searchable, filterable, with per-book AI cost.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $books = Book::query()
            ->with('user:id,name,email')
            ->withCount([
                'pages as pages_total',
                'pages as pages_done' => fn ($query) => $query->where('status', PageStatus::Complete),
            ])
            ->withSum('aiUsage as ai_cost', 'cost_usd')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('child_name', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($q) => $q->where('email', 'like', "%{$search}%"));

                    if (ctype_digit($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/books/index', [
            'books' => $books->through(fn (Book $book): array => [
                'id' => $book->id,
                'childName' => $book->child_name,
                'userEmail' => $book->user->email ?? '',
                'artStyle' => $book->art_style,
                'language' => $book->language,
                'status' => $book->status->value,
                'pagesTotal' => (int) $book->getAttribute('pages_total'),
                'pagesDone' => (int) $book->getAttribute('pages_done'),
                'aiCost' => round((float) $book->getAttribute('ai_cost'), 2),
                'paid' => $book->paid_at !== null,
                'createdAt' => $book->created_at?->toDateTimeString() ?? '',
            ]),
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => array_column(BookStatus::cases(), 'value'),
        ]);
    }

    /**
     * One book with its full prompt journal (what was actually sent to the
     * image models, attempt by attempt) and the admin actions.
     */
    public function show(int $id): Response
    {
        $book = Book::query()->with(['user:id,name,email', 'pages'])->findOrFail($id);

        $pageNumbers = $book->pages->pluck('page_number', 'id');

        $journal = ImagePrompt::query()
            ->where('book_id', $book->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ImagePrompt $prompt): array => [
                'id' => $prompt->id,
                'purpose' => $prompt->purpose,
                'pageNumber' => $prompt->page_id !== null ? $pageNumbers[$prompt->page_id] ?? null : null,
                'attempt' => $prompt->attempt,
                'variant' => $prompt->variant,
                'accepted' => $prompt->accepted,
                'prompt' => $prompt->prompt,
                'createdAt' => $prompt->created_at?->toDateTimeString() ?? '',
            ]);

        $activePaths = [
            'cover' => $book->cover_image_path,
            'sheet' => $book->hero_sheet_path,
            ...$book->pages->mapWithKeys(fn (Page $p): array => ["page-{$p->page_number}" => $p->image_path]),
        ];

        $versions = ImageVersion::query()
            ->where('book_id', $book->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (ImageVersion $version) use ($activePaths): array {
                $slotKey = $version->slot === 'page' ? "page-{$version->page_number}" : $version->slot;

                return [
                    'id' => $version->id,
                    'slot' => $slotKey,
                    'url' => $version->url(),
                    'active' => ($activePaths[$slotKey] ?? null) === $version->path,
                    'engine' => $version->engineLabel(),
                    'prompt' => (string) $version->prompt,
                    'createdAt' => $version->created_at?->toDateTimeString() ?? '',
                ];
            })
            ->filter(fn (array $version): bool => $version['url'] !== null)
            ->values();

        $imageProvider = (string) config('cubfable.ai.image_provider');
        $imageModels = (array) config('cubfable.ai.models.image');

        return Inertia::render('admin/books/show', [
            'versions' => $versions,
            // The engine generation would use right now, plus each provider's
            // configured model - so every action can confirm "with model X".
            'engines' => [
                'currentProvider' => $imageProvider,
                'models' => $imageModels,
            ],
            'book' => [
                'id' => $book->id,
                'childName' => $book->child_name,
                'userEmail' => $book->user->email ?? '',
                'ageRange' => $book->age_range,
                'theme' => $book->theme,
                'subject' => $book->subject,
                'lifeLesson' => $book->life_lesson,
                'artStyle' => $book->art_style,
                'language' => $book->language,
                'status' => $book->status->value,
                'paid' => $book->paid_at !== null,
                'coverImageUrl' => $book->cover_image_url,
                'coverStatus' => $book->cover_status,
                'storyBible' => $book->story_bible,
                'createdAt' => $book->created_at?->toDateTimeString() ?? '',
                'pages' => $book->pages->map(fn (Page $page): array => [
                    'pageNumber' => $page->page_number,
                    'status' => $page->status->value,
                    'imageUrl' => $page->image_url,
                ])->all(),
            ],
            'journal' => $journal,
            'artStyles' => array_column(ArtStyle::cases(), 'value'),
        ]);
    }

    /**
     * Requeue a stuck or failed book, keeping finished images.
     */
    public function resume(int $id, BookRescueService $rescue): RedirectResponse
    {
        $book = Book::query()->findOrFail($id);

        try {
            $rescue->resume($book);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['book' => $exception->getMessage()]);
        }

        return back();
    }

    /**
     * Restart a book from scratch regardless of its status: wipe the story
     * and every image, then requeue a full generation run.
     */
    public function restart(int $id, BookRestarter $restarter): RedirectResponse
    {
        $restarter->restart(Book::query()->findOrFail($id));

        return back();
    }

    /**
     * Queue a regeneration for one image of any user's book: the cover or a
     * single page. The replaced image stays available as a version, and an
     * optional engine override applies to this run only - for comparing
     * providers/models without touching the stored settings.
     */
    public function regenerateImage(Request $request, int $id): RedirectResponse
    {
        $book = Book::query()->findOrFail($id);

        $validated = $request->validate([
            'target' => ['required', 'string', 'regex:/^(cover|page-\d+)$/'],
            'provider' => ['nullable', 'in:openai,gemini,openrouter,flow,grok,piapi,replicate'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $provider = $validated['provider'] ?? null;
        $model = $validated['model'] ?? null;

        Log::info("Admin queued a regeneration of {$validated['target']}.", array_filter([
            'book_id' => $book->id,
            'provider' => $provider,
            'model' => $model,
        ]));

        if ($validated['target'] === 'cover') {
            $book->update(['cover_status' => 'generating']);
            RegenerateCoverJob::dispatch($book->id, $provider, $model);

            return back();
        }

        $pageNumber = (int) substr($validated['target'], 5);
        $page = $book->pages()->where('page_number', $pageNumber)->firstOrFail();

        $page->update(['status' => PageStatus::Generating]);
        RegeneratePageJob::dispatch($page->id, $provider, $model);

        return back();
    }

    /**
     * Point a slot back at an older image version. No files move: the
     * version's file is still on disk and simply becomes active again.
     */
    public function restoreImage(Request $request, int $id): RedirectResponse
    {
        $book = Book::query()->findOrFail($id);

        $validated = $request->validate([
            'versionId' => ['required', 'integer'],
        ]);

        $version = ImageVersion::query()
            ->where('book_id', $book->id)
            ->findOrFail((int) $validated['versionId']);

        if ($version->url() === null) {
            throw ValidationException::withMessages(['book' => 'That version\'s file no longer exists on disk.']);
        }

        Log::info("Admin restored {$version->slot} to version {$version->id} ({$version->path}).", ['book_id' => $book->id]);

        if ($version->slot === 'cover') {
            $book->update(['cover_image_path' => $version->path, 'cover_prompt' => $version->prompt, 'cover_status' => null]);
        } elseif ($version->slot === 'sheet') {
            $book->update(['hero_sheet_path' => $version->path, 'hero_sheet_prompt' => $version->prompt]);
        } else {
            $page = $book->pages()->where('page_number', $version->page_number)->first();

            if ($page === null) {
                throw ValidationException::withMessages(['book' => "Page {$version->page_number} no longer exists on this book."]);
            }

            $page->update(['image_path' => $version->path, 'image_prompt' => $version->prompt, 'status' => PageStatus::Complete]);
        }

        return back();
    }

    /**
     * Mark a book complete when its content is actually all there - the fix
     * for a run whose worker died after the last image (stranded generating).
     */
    public function heal(int $id): RedirectResponse
    {
        $book = Book::query()->with('pages')->findOrFail($id);

        $missing = [];

        if ($book->cover_image_path === null) {
            $missing[] = 'cover';
        }

        if ($book->pages->isEmpty()) {
            $missing[] = 'story';
        }

        foreach ($book->pages as $page) {
            if ($page->status !== PageStatus::Complete || $page->image_path === null) {
                $missing[] = 'page '.$page->page_number;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'book' => 'Cannot mark complete; missing: '.implode(', ', $missing),
            ]);
        }

        $book->update(['status' => BookStatus::Complete]);

        return back();
    }

    /**
     * Re-render a finished book in another style (admin can do it for any user).
     */
    public function restyle(Request $request, int $id, BookRestyler $restyler): RedirectResponse
    {
        $book = Book::query()->findOrFail($id);

        if (! in_array($book->status, [BookStatus::Complete, BookStatus::Failed], true)) {
            throw ValidationException::withMessages([
                'artStyle' => 'Only complete or failed books can be restyled.',
            ]);
        }

        $validated = $request->validate([
            'artStyle' => ['required', Rule::enum(ArtStyle::class)],
        ]);

        $restyler->restyle($book, $validated['artStyle']);

        return back();
    }

    /**
     * The book's own A-to-Z log (everything logged with its book_id), served
     * as plain text in the browser.
     */
    public function log(int $id): HttpResponse
    {
        $book = Book::query()->findOrFail($id);
        $path = storage_path("logs/books/book-{$book->id}.log");

        $content = File::exists($path)
            ? (string) File::get($path)
            : "No log entries recorded for book {$book->id} yet.";

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Fully remove a book and its generated art. Admin-only - the public app
     * never deletes paid books.
     */
    public function destroy(int $id, BookImageStorage $images): RedirectResponse
    {
        $book = Book::query()->findOrFail($id);

        $book->delete();

        $images->deleteDirectory("books/{$book->id}");
        File::delete(storage_path("logs/books/book-{$book->id}.log"));

        return redirect()->route('admin.books');
    }
}
