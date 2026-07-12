<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\StoryLanguage;
use App\Models\Book;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates and edits unpaid draft books from the validated wizard payload.
 * Shared by the web wizard and the mobile API so cast resolution, photo
 * storage, and pivot bookkeeping exist exactly once.
 */
class DraftBookManager
{
    public function __construct(private BookImageStorage $images) {}

    /**
     * Create a draft book with its cast. Generation is started only after
     * payment (webhook or reconcile), never here.
     *
     * @param  array<string, mixed>  $input  validated StoreBookRequest payload
     */
    public function createDraft(User $user, array $input): Book
    {
        /** @var array<int, array<string, mixed>> $cast */
        $cast = $input['characters'];

        $mainIndex = $this->mainCastIndex($cast);

        try {
            return DB::transaction(function () use ($input, $cast, $mainIndex, $user): Book {
                $resolved = [];

                foreach ($cast as $member) {
                    $resolved[] = $this->resolveCastMember($user, $member);
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
    }

    /**
     * Apply wizard changes to an unpaid draft (fields + cast). The cast pivot
     * is rebuilt; the characters themselves stay in the user's library.
     *
     * @param  array<string, mixed>  $input  validated UpdateBookRequest payload
     */
    public function updateDraft(User $user, Book $book, array $input): void
    {
        /** @var array<int, array<string, mixed>> $cast */
        $cast = $input['characters'];

        $mainIndex = $this->mainCastIndex($cast);

        try {
            DB::transaction(function () use ($book, $input, $cast, $mainIndex, $user): void {
                $resolved = [];

                foreach ($cast as $member) {
                    $resolved[] = $this->resolveCastMember($user, $member);
                }

                $hero = $resolved[$mainIndex];

                $book->update([
                    'child_name' => $hero->name,
                    'age_range' => $input['ageRange'],
                    'theme' => $input['theme'],
                    'subject' => $input['subject'],
                    'life_lesson' => $input['lifeLesson'],
                    'art_style' => $input['artStyle'],
                    'font' => $input['font'],
                    'language' => $input['language'] ?? $book->language,
                ]);

                $book->characters()->detach();

                foreach ($resolved as $index => $character) {
                    $book->characters()->attach($character->id, [
                        'is_main' => $index === $mainIndex,
                        'sort_order' => $index,
                    ]);
                }
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['characters' => $exception->getMessage()]);
        }
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
    private function resolveCastMember(User $user, array $member): Character
    {
        $existing = null;

        if (($member['characterId'] ?? null) !== null) {
            $existing = $user->characters()->whereKey($member['characterId'])->first();
        }

        if ($existing !== null) {
            $existing->name = $member['name'];
            $existing->role = $member['role'] ?? $existing->role;
            $existing->age_group = $member['ageGroup'] ?? $existing->age_group;
            $existing->description = $member['description'] ?? $existing->description;

            if (($member['photoUrl'] ?? null) !== null) {
                $previousPhotoPath = $existing->photo_path;
                $existing->photo_path = $this->images->storeDataUrl($member['photoUrl'], "characters/{$existing->id}");
                $existing->appearance = null;

                DB::afterCommit(fn () => $this->images->delete($previousPhotoPath));
            }

            $existing->save();

            return $existing;
        }

        $character = $user->characters()->create([
            'name' => $member['name'],
            'role' => $member['role'] ?? null,
            'age_group' => $member['ageGroup'] ?? null,
            'description' => $member['description'] ?? null,
        ]);

        if (($member['photoUrl'] ?? null) !== null) {
            $character->photo_path = $this->images->storeDataUrl($member['photoUrl'], "characters/{$character->id}");
            $character->save();
        }

        return $character;
    }
}
