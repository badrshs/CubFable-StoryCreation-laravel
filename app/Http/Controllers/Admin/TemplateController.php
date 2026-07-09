<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TemplateRequest;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $templates = Template::query()
            ->withCount('books')
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('title', 'like', "%{$search}%")->orWhere('theme', 'like', "%{$search}%"),
            ))
            ->orderBy('title')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/templates/index', [
            'templates' => $templates->through(fn (Template $template): array => [
                'id' => $template->id,
                'title' => $template->title,
                'theme' => $template->theme,
                'ageMin' => $template->age_min,
                'ageMax' => $template->age_max,
                'pageCount' => $template->page_count,
                'booksCount' => (int) $template->getAttribute('books_count'),
                'coverImageUrl' => $template->cover_image_url,
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/templates/form', [
            'template' => null,
            ...$this->formOptions(),
        ]);
    }

    public function store(TemplateRequest $request): RedirectResponse
    {
        $template = Template::query()->create($this->payload($request));

        return redirect()->route('admin.templates.edit', ['id' => $template->id]);
    }

    public function edit(int $id): Response
    {
        $template = Template::query()->withCount('books')->findOrFail($id);

        return Inertia::render('admin/templates/form', [
            'template' => [
                'id' => $template->id,
                'title' => $template->title,
                'description' => $template->description,
                'theme' => $template->theme,
                'ageMin' => $template->age_min,
                'ageMax' => $template->age_max,
                'pageCount' => $template->page_count,
                'coverImageUrl' => $template->cover_image_url,
                'lifeLessons' => $template->life_lessons ?? [],
                'artStyles' => $template->art_styles ?? [],
                'subjects' => $template->subjects ?? [],
                'fonts' => $template->fonts ?? [],
                'imagePrompt' => $template->image_prompt,
                'booksCount' => (int) $template->getAttribute('books_count'),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(TemplateRequest $request, int $id): RedirectResponse
    {
        Template::query()->findOrFail($id)->update($this->payload($request));

        return back();
    }

    /**
     * Templates with books cannot be removed (the FK's restrictOnDelete is
     * the backstop); surface that as a friendly error instead of a 500.
     */
    public function destroy(int $id): RedirectResponse
    {
        $template = Template::query()->withCount('books')->findOrFail($id);

        if ((int) $template->getAttribute('books_count') > 0) {
            throw ValidationException::withMessages([
                'template' => 'This template has books and cannot be deleted.',
            ]);
        }

        $template->delete();

        return redirect()->route('admin.templates');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'artStyleOptions' => array_column(ArtStyle::cases(), 'value'),
            'fontOptions' => array_column(FontChoice::cases(), 'value'),
            'pageBounds' => [
                'min' => (int) config('cubfable.pages_min', 4),
                'max' => (int) config('cubfable.pages_max', 10),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TemplateRequest $request): array
    {
        $validated = $request->validated();

        return [
            'title' => $validated['title'],
            'description' => $validated['description'],
            'theme' => $validated['theme'],
            'age_min' => $validated['age_min'],
            'age_max' => $validated['age_max'],
            'page_count' => $validated['page_count'],
            'cover_image_url' => (string) ($validated['cover_image_url'] ?? ''),
            'life_lessons' => array_values($validated['life_lessons']),
            'art_styles' => array_values($validated['art_styles']),
            'subjects' => array_values($validated['subjects']),
            'fonts' => array_values($validated['fonts']),
            'image_prompt' => (string) ($validated['image_prompt'] ?? ''),
        ];
    }
}
