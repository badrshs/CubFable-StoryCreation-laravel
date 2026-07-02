<?php

namespace App\Http\Controllers;

use App\Enums\BookStatus;
use App\Enums\StoryLanguage;
use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Http\Requests\StoreBookRequest;
use App\Jobs\RegenerateCoverJob;
use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Models\User;
use App\Services\BookImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class BookController extends Controller
{
    use MapsCubfableProps;

    /**
     * Show the three-step creation wizard for a template.
     */
    public function create(Request $request, Template $template): Response
    {
        $savedCharacters = $request->user()->characters()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('create-wizard', [
            'template' => $this->templateProps($template),
            'savedCharacters' => $savedCharacters
                ->map(fn (Character $character): array => $this->characterProps($character))
                ->all(),
        ]);
    }

    /**
     * Create a draft book with its cast, then send the user to checkout.
     * Generation is started only after payment (webhook or reconcile), never here.
     */
    public function store(StoreBookRequest $request, BookImageStorage $images): RedirectResponse
    {
        $input = $request->validated();
        $user = $request->user();

        /** @var array<int, array<string, mixed>> $cast */
        $cast = $input['characters'];

        $mainIndex = $this->mainCastIndex($cast);

        try {
            $book = DB::transaction(function () use ($input, $cast, $mainIndex, $user, $images): Book {
                $resolved = [];

                foreach ($cast as $member) {
                    $resolved[] = $this->resolveCastMember($user, $member, $images);
                }

                $hero = $resolved[$mainIndex];

                $book = $user->books()->create([
                    'template_id' => $input['templateId'],
                    'child_name' => $hero->name,
                    'age_range' => $input['ageRange'],
                    'theme' => $input['theme'],
                    'subject' => $input['subject'],
                    'life_lesson' => $input['lifeLesson'],
                    'art_style' => $input['artStyle'],
                    'font' => $input['font'],
                    'language' => $input['language'] ?? StoryLanguage::English->value,
                    'status' => BookStatus::Draft,
                ]);

                foreach ($resolved as $index => $character) {
                    $book->characters()->attach($character->id, [
                        'is_main' => $index === $mainIndex,
                        'sort_order' => $index,
                    ]);
                }

                return $book;
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['characters' => $exception->getMessage()]);
        }

        return redirect()->route('checkout.show', ['id' => $book->id]);
    }

    /**
     * Show the user's gallery, newest books first.
     */
    public function index(Request $request): Response
    {
        $books = $request->user()->books()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('gallery', [
            'books' => $books->map(fn (Book $book): array => $this->bookProps($book))->all(),
        ]);
    }

    /**
     * Show the reader for one of the user's books. A book owned by someone
     * else is indistinguishable from one that does not exist (404, never 403).
     */
    public function show(Request $request, int $id): Response
    {
        $book = $request->user()->books()
            ->with(['pages', 'characters'])
            ->findOrFail($id);

        return Inertia::render('reader', [
            'book' => $this->bookWithPagesProps($book),
        ]);
    }

    /**
     * Queue a cover regeneration for a paid book.
     */
    public function regenerateCover(Request $request, int $id): RedirectResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        abort_if($book->status === BookStatus::Draft, 402);

        $book->update(['cover_status' => 'generating']);

        RegenerateCoverJob::dispatch($book->id);

        return back();
    }

    /**
     * The hero is the entry flagged isMain (or the first entry as a fallback).
     *
     * @param  array<int, array<string, mixed>>  $cast
     */
    private function mainCastIndex(array $cast): int
    {
        foreach ($cast as $index => $member) {
            if ((bool) ($member['isMain'] ?? false)) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Resolve a wizard cast entry to a character owned by the user: reuse a
     * saved character when characterId is given and belongs to the user
     * (refreshing any provided fields), otherwise create a new reusable one.
     * A characterId that is not the user's falls through to create, so a
     * caller can never attach another account's character.
     *
     * @param  array<string, mixed>  $member
     */
    private function resolveCastMember(User $user, array $member, BookImageStorage $images): Character
    {
        $existing = null;

        if (($member['characterId'] ?? null) !== null) {
            $existing = $user->characters()->whereKey($member['characterId'])->first();
        }

        if ($existing !== null) {
            $existing->name = $member['name'];
            $existing->role = $member['role'] ?? $existing->role;
            $existing->description = $member['description'] ?? $existing->description;

            if (($member['photoUrl'] ?? null) !== null) {
                $previousPhotoPath = $existing->photo_path;
                $existing->photo_path = $images->storeDataUrl($member['photoUrl'], "characters/{$existing->id}");
                $existing->appearance = null;

                DB::afterCommit(fn () => $images->delete($previousPhotoPath));
            }

            $existing->save();

            return $existing;
        }

        $character = $user->characters()->create([
            'name' => $member['name'],
            'role' => $member['role'] ?? null,
            'description' => $member['description'] ?? null,
        ]);

        if (($member['photoUrl'] ?? null) !== null) {
            $character->photo_path = $images->storeDataUrl($member['photoUrl'], "characters/{$character->id}");
            $character->save();
        }

        return $character;
    }
}
