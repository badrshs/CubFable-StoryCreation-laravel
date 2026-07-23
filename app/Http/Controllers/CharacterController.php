<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MapsCubfableProps;
use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Models\Character;
use App\Services\BookImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CharacterController extends Controller
{
    use MapsCubfableProps;

    /**
     * Show the saved-character library, newest first.
     */
    public function index(Request $request): Response
    {
        $characters = $request->user()->characters()
            ->with('portraits')
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
    public function store(StoreCharacterRequest $request, BookImageStorage $images): RedirectResponse
    {
        $input = $request->validated();

        try {
            DB::transaction(function () use ($request, $input, $images): void {
                $character = $request->user()->characters()->create([
                    'name' => $input['name'],
                    'role' => $input['role'] ?? null,
                    'age_group' => $input['ageGroup'] ?? null,
                    'description' => $input['description'] ?? null,
                ]);

                if (($input['photoUrl'] ?? null) !== null) {
                    $character->photo_path = $images->storeDataUrl($input['photoUrl'], "characters/{$character->id}");
                    $character->save();
                }
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['photoUrl' => $exception->getMessage()]);
        }

        return back();
    }

    /**
     * Update a saved character with PATCH semantics: only fields the client
     * actually sent are touched; an explicit null clears them. A new (or
     * cleared) photo invalidates the cached AI-derived appearance so the next
     * generation re-derives it, and the old photo file is deleted.
     */
    public function update(UpdateCharacterRequest $request, BookImageStorage $images, int $id): RedirectResponse
    {
        $character = $request->user()->characters()->findOrFail($id);
        $input = $request->validated();

        $character->name = $input['name'];

        if (array_key_exists('role', $input)) {
            $character->role = $input['role'];
        }

        if (array_key_exists('ageGroup', $input)) {
            $character->age_group = $input['ageGroup'];
        }

        if (array_key_exists('description', $input)) {
            $character->description = $input['description'];
        }

        $previousPhotoPath = null;

        $photoChanged = false;

        if (array_key_exists('photoUrl', $input)) {
            $previousPhotoPath = $character->photo_path;

            try {
                $character->photo_path = $input['photoUrl'] === null
                    ? null
                    : $images->storeDataUrl($input['photoUrl'], "characters/{$character->id}");
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['photoUrl' => $exception->getMessage()]);
            }

            $character->appearance = null;
            $photoChanged = $previousPhotoPath !== $character->photo_path;
        }

        $character->save();

        if ($previousPhotoPath !== null && $previousPhotoPath !== $character->photo_path) {
            $images->delete($previousPhotoPath);
        }

        // A new (or removed) photo forgets every cached stylized portrait so
        // the next generation rebuilds the reference. Only the rows are
        // dropped; the files may still be the sheet of a book generated
        // earlier, so they are never deleted here.
        if ($photoChanged) {
            $character->portraits()->delete();
        }

        return back();
    }

    /**
     * Owner-scoped delete. Deleting an id that is not the caller's simply
     * matches nothing; the response is the same either way, so the existence
     * of foreign ids is not revealed.
     */
    public function destroy(Request $request, BookImageStorage $images, int $id): RedirectResponse
    {
        $character = $request->user()->characters()->whereKey($id)->first();

        if ($character !== null) {
            $images->delete($character->photo_path);
            // Portrait rows cascade on delete; their files are left in place
            // since books generated earlier may still reference them.
            $character->delete();
        }

        return back();
    }
}
