<?php

namespace App\Services;

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Manages the user's reusable character library. Shared by the web library
 * and the mobile API so the subtle photo-replacement and appearance-cache
 * rules exist exactly once.
 */
class CharacterLibrarian
{
    public function __construct(private BookImageStorage $images) {}

    /**
     * Create a reusable character, decoding an optional photo data URL onto
     * the public disk.
     *
     * @param  array<string, mixed>  $input  validated StoreCharacterRequest payload
     */
    public function create(User $user, array $input): Character
    {
        try {
            return DB::transaction(function () use ($user, $input): Character {
                $character = $user->characters()->create([
                    'name' => $input['name'],
                    'role' => $input['role'] ?? null,
                    'age_group' => $input['ageGroup'] ?? null,
                    'description' => $input['description'] ?? null,
                ]);

                if (($input['photoUrl'] ?? null) !== null) {
                    $character->photo_path = $this->images->storeDataUrl($input['photoUrl'], "characters/{$character->id}");
                    $character->save();
                }

                return $character;
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['photoUrl' => $exception->getMessage()]);
        }
    }

    /**
     * Update a saved character with PATCH semantics: only fields the client
     * actually sent are touched; an explicit null clears them. A new (or
     * cleared) photo invalidates the cached AI-derived appearance so the next
     * generation re-derives it, and the old photo file is deleted.
     *
     * @param  array<string, mixed>  $input  validated UpdateCharacterRequest payload
     */
    public function update(Character $character, array $input): Character
    {
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

        if (array_key_exists('photoUrl', $input)) {
            $previousPhotoPath = $character->photo_path;

            try {
                $character->photo_path = $input['photoUrl'] === null
                    ? null
                    : $this->images->storeDataUrl($input['photoUrl'], "characters/{$character->id}");
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['photoUrl' => $exception->getMessage()]);
            }

            $character->appearance = null;
        }

        $character->save();

        if ($previousPhotoPath !== null && $previousPhotoPath !== $character->photo_path) {
            $this->images->delete($previousPhotoPath);
        }

        return $character;
    }

    /**
     * Delete a character and its stored photo.
     */
    public function delete(Character $character): void
    {
        $this->images->delete($character->photo_path);
        $character->delete();
    }
}
