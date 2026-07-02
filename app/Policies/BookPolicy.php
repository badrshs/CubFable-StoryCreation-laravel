<?php

namespace App\Policies;

use App\Models\Book;
use App\Models\User;

/**
 * Defense-in-depth ownership checks. Controllers already scope every lookup
 * through $request->user()->books() (foreign books 404), so these gates are a
 * second layer, not the primary guard.
 */
class BookPolicy
{
    /**
     * Determine whether the user can view the book.
     */
    public function view(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }

    /**
     * Determine whether the user can update the book.
     */
    public function update(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }

    /**
     * Determine whether the user can delete the book.
     */
    public function delete(User $user, Book $book): bool
    {
        return $user->id === $book->user_id;
    }
}
