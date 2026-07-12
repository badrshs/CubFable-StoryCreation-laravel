<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Models\Character;
use App\Services\CharacterLibrarian;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CharacterController extends Controller
{
    use MapsCubfableProps;

    /**
     * Show the saved-character library, newest first.
     */
    public function index(Request $request): Response
    {
        $characters = $request->user()->characters()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('library', [
            'characters' => $characters
                ->map(fn (Character $character): array => $this->characterProps($character))
                ->all(),
        ]);
    }

    /**
     * Create a reusable character, decoding an optional photo data URL onto
     * the public disk.
     */
    public function store(StoreCharacterRequest $request, CharacterLibrarian $librarian): RedirectResponse
    {
        $librarian->create($request->user(), $request->validated());

        return back();
    }

    /**
     * Update a saved character with PATCH semantics: only fields the client
     * actually sent are touched; an explicit null clears them.
     */
    public function update(UpdateCharacterRequest $request, CharacterLibrarian $librarian, int $id): RedirectResponse
    {
        $character = $request->user()->characters()->findOrFail($id);

        $librarian->update($character, $request->validated());

        return back();
    }

    /**
     * Owner-scoped delete. Deleting an id that is not the caller's simply
     * matches nothing; the response is the same either way, so the existence
     * of foreign ids is not revealed.
     */
    public function destroy(Request $request, CharacterLibrarian $librarian, int $id): RedirectResponse
    {
        $character = $request->user()->characters()->whereKey($id)->first();

        if ($character !== null) {
            $librarian->delete($character);
        }

        return back();
    }
}
