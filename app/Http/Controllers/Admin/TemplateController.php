<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ArtStyle;
use App\Enums\FontChoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TemplateRequest;
use App\Jobs\EngineOverride;
use App\Models\Template;
use App\Services\AI\ImageSizePolicy;
use App\Services\AI\SafeImageGenerator;
use App\Services\AI\UsageCollector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
                // A data: URL is the seeded SVG placeholder, not real art.
                'needsCover' => $template->cover_image_url === null
                    || $template->cover_image_url === ''
                    || str_starts_with((string) $template->cover_image_url, 'data:'),
                'hasImagePrompt' => trim((string) $template->image_prompt) !== '',
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
     * Generate the template's cover art from its stored image prompt (PAID -
     * one real image call, synchronous). The file lands at
     * public/images/templates/{slug}.jpg like the hand-made covers, and an
     * optional engine override applies to this call only.
     */
    public function generateCover(Request $request, int $id, SafeImageGenerator $images, UsageCollector $usage, ImageSizePolicy $sizes): RedirectResponse
    {
        $template = Template::query()->findOrFail($id);

        $validated = $request->validate([
            'provider' => ['nullable', 'in:openai,gemini,openrouter,flow,grok,piapi,replicate'],
            'model' => ['nullable', 'string', 'max:200'],
        ]);

        $prompt = trim((string) $template->image_prompt);

        if ($prompt === '') {
            throw ValidationException::withMessages([
                'template' => 'This template has no cover image prompt to generate from.',
            ]);
        }

        EngineOverride::apply($validated['provider'] ?? null, $validated['model'] ?? null);
        Log::info("Generating template cover for [{$template->title}].", ['template_id' => $template->id]);

        try {
            $image = $images->generate($prompt, $sizes->bookSize(), [], "template {$template->id} cover");
        } finally {
            $usage->flush(null);
        }

        $relative = 'images/templates/'.Str::slug($template->title).'.jpg';
        File::ensureDirectoryExists(public_path('images/templates'));
        File::put(public_path($relative), $this->toJpeg($image->bytes));

        $template->update(['cover_image_url' => '/'.$relative]);

        return back();
    }

    /**
     * Transcode generated PNG bytes to JPEG (the format every template cover
     * ships as); unreadable bytes pass through untouched.
     */
    private function toJpeg(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return $bytes;
        }

        ob_start();
        imagejpeg($source, null, 88);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);

        return $jpeg !== '' ? $jpeg : $bytes;
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
