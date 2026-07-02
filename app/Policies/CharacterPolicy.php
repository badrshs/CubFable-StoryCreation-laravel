<?php

namespace App\Policies;

use App\Models\Character;
use App\Models\User;

/**
 * Defense-in-depth ownership checks. Controllers already scope every lookup
 * through $request->user()->characters() (foreign characters 404 or no-op),
 * so these gates are a second layer, not the primary guard.
 */
class CharacterPolicy
{
    /**
     * Determine whether the user can view the character.
     */
    public function view(User $user, Character $character): bool
    {
        return $user->id === $character->user_id;
    }

    /**
     * Determine whether the user can update the character.
     */
    public function update(User $user, Character $character): bool
    {
        return $user->id === $character->user_id;
    }

    /**
     * Determine whether the user can delete the character.
     */
    public function delete(User $user, Character $character): bool
    {
        return $user->id === $character->user_id;
    }
}
