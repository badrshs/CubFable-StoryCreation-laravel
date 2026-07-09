<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Book;
use App\Models\Character;
use App\Models\Page;
use App\Models\Template;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Builds the camelCase Inertia prop arrays that match the shapes declared in
 * resources/js/types/cubfable.ts, so every page receives identical book,
 * page, character, and template payloads.
 */
trait MapsCubfableProps
{
    /**
     * @return array{id: int, title: string, description: string, theme: string, coverImageUrl: string|null, pageCount: int, ageMin: int, ageMax: int, lifeLessons: array<int, string>, subjects: array<int, string>}
     */
    protected function templateProps(Template $template): array
    {
        return [
            'id' => $template->id,
            'title' => $template->title,
            'description' => $template->description,
            'theme' => $template->theme,
            'coverImageUrl' => $template->cover_image_url,
            'pageCount' => $template->page_count,
            'ageMin' => $template->age_min,
            'ageMax' => $template->age_max,
            'lifeLessons' => $template->life_lessons,
            'subjects' => $template->subjects ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookProps(Book $book): array
    {
        return [
            'id' => $book->id,
            'childName' => $book->child_name,
            'ageRange' => $book->age_range,
            'theme' => $book->theme,
            'subject' => $book->subject,
            'lifeLesson' => $book->life_lesson,
            'artStyle' => $book->art_style,
            'font' => $book->font,
            'language' => $book->language,
            'status' => $book->status->value,
            'coverImageUrl' => $book->cover_image_url,
            'coverStatus' => $book->cover_status,
            'createdAt' => $book->created_at?->toISOString(),
        ];
    }

    /**
     * The reader payload: the book plus its ordered pages and cast.
     *
     * @return array<string, mixed>
     */
    protected function bookWithPagesProps(Book $book): array
    {
        return [
            ...$this->bookProps($book),
            'pages' => $book->pages->map(fn (Page $page): array => $this->pageProps($page))->all(),
            'characters' => $book->characters->map(fn (Character $character): array => $this->characterProps($character))->all(),
        ];
    }

    /**
     * @return array{id: int, pageNumber: int, text: string, imageUrl: string|null, status: string}
     */
    protected function pageProps(Page $page): array
    {
        return [
            'id' => $page->id,
            'pageNumber' => $page->page_number,
            'text' => $page->text,
            'imageUrl' => $page->image_url,
            'status' => $page->status->value,
        ];
    }

    /**
     * When the character was loaded through a book's cast, the pivot flag is
     * exposed as isMain; standalone (library) characters omit it.
     *
     * @return array<string, mixed>
     */
    protected function characterProps(Character $character): array
    {
        $props = [
            'id' => $character->id,
            'name' => $character->name,
            'role' => $character->role,
            'ageGroup' => $character->age_group,
            'description' => $character->description,
            'photoUrl' => $character->photo_url,
        ];

        $pivot = $character->relationLoaded('pivot') ? $character->getRelation('pivot') : null;

        if ($pivot instanceof Pivot) {
            $props['isMain'] = (bool) $pivot->getAttribute('is_main');
        }

        return $props;
    }
}
