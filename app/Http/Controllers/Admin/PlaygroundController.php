<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgeRange;
use App\Enums\ArtStyle;
use App\Enums\StoryLanguage;
use App\Http\Controllers\Controller;
use App\Jobs\EngineOverride;
use App\Models\Book;
use App\Models\Character;
use App\Models\Template;
use App\Services\AI\AiManager;
use App\Services\AI\ImageSizePolicy;
use App\Services\AI\Replicate\ReplicateModelCatalog;
use App\Services\AI\SafeImageGenerator;
use App\Services\AI\UsageCollector;
use App\Services\StoryGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Test bench for the prompt flows: preview exactly what generation would send
 * (free), and optionally execute a single text or image call against the
 * configured providers (paid, explicit buttons).
 */
class PlaygroundController extends Controller
{
    public function index(ReplicateModelCatalog $catalog): Response
    {
        return Inertia::render('admin/playground', [
            'replicateEngines' => $catalog->options(),
            'templates' => Template::query()->orderBy('title')->get(['id', 'title', 'theme', 'page_count'])
                ->map(fn (Template $template): array => [
                    'id' => $template->id,
                    'title' => $template->title,
                    'theme' => $template->theme,
                    'pageCount' => $template->page_count,
                ]),
            'books' => Book::query()->orderByDesc('id')->limit(30)->get(['id', 'child_name', 'art_style', 'status'])
                ->map(fn (Book $book): array => [
                    'id' => $book->id,
                    'childName' => $book->child_name,
                    'artStyle' => $book->art_style,
                    'status' => $book->status->value,
                ]),
            'providers' => $this->providerInfo(),
            'artStyles' => array_column(ArtStyle::cases(), 'value'),
            'ageRanges' => array_column(AgeRange::cases(), 'value'),
            'languages' => array_column(StoryLanguage::cases(), 'value'),
        ]);
    }

    /**
     * Compose every prompt for an existing book OR a sample brief. Zero AI
     * calls - this is exactly what generation would send.
     */
    public function preview(Request $request, StoryGenerator $generator): JsonResponse
    {
        $validated = $request->validate([
            'bookId' => ['nullable', 'integer', 'exists:books,id'],
            'templateId' => ['required_without:bookId', 'nullable', 'integer', 'exists:templates,id'],
            'childName' => ['required_without:bookId', 'nullable', 'string', 'max:40'],
            'ageRange' => ['nullable', Rule::enum(AgeRange::class)],
            'artStyle' => ['nullable', Rule::enum(ArtStyle::class)],
            'language' => ['nullable', Rule::enum(StoryLanguage::class)],
            'subject' => ['nullable', 'string', 'max:120'],
            'lifeLesson' => ['nullable', 'string', 'max:60'],
        ]);

        if (($validated['bookId'] ?? null) !== null) {
            $book = Book::query()->findOrFail((int) $validated['bookId']);

            return response()->json([
                'prompts' => $generator->previewPrompts($book),
                'providers' => $this->providerInfo(),
            ]);
        }

        $template = Template::query()->findOrFail((int) $validated['templateId']);

        $book = new Book([
            'template_id' => $template->id,
            'child_name' => $validated['childName'],
            'age_range' => $validated['ageRange'] ?? '4-6',
            'theme' => $template->theme,
            'subject' => $validated['subject'] ?? ($template->subjects[0] ?? 'adventure'),
            'life_lesson' => $validated['lifeLesson'] ?? ($template->life_lessons[0] ?? 'Kindness'),
            'art_style' => $validated['artStyle'] ?? ($template->art_styles[0] ?? 'storybook'),
            'font' => $template->fonts[0] ?? 'classic',
            'language' => $validated['language'] ?? 'en',
        ]);
        $book->template_id = $template->id;

        $cast = new Collection([
            new Character(['name' => $validated['childName'], 'role' => 'self']),
        ]);

        return response()->json([
            'prompts' => $generator->previewPrompts($book, $cast),
            'providers' => $this->providerInfo(),
        ]);
    }

    /**
     * Execute one real text call with the given prompt (PAID - explicit
     * button). Usage lands in ai_usage with no book.
     */
    public function runText(Request $request, AiManager $ai, UsageCollector $usage): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $content = $ai->generateText($validated['prompt'], 8192);
        } finally {
            $usage->flush(null);
        }

        return response()->json(['content' => $content]);
    }

    /**
     * Execute one real image call with the given prompt (PAID - explicit
     * button). The image is returned inline and not stored. An optional
     * engine override applies to this request only, so any catalog engine
     * can be smoke-tested without touching the stored settings.
     */
    public function runImage(Request $request, SafeImageGenerator $images, UsageCollector $usage, ImageSizePolicy $sizes): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:20000'],
            'size' => ['nullable', 'regex:/^\d{3,4}x\d{3,4}$/'],
            'provider' => ['nullable', 'in:openai,gemini,openrouter,flow,grok,piapi,replicate'],
            'model' => ['nullable', 'string', 'max:200'],
        ]);

        EngineOverride::apply($validated['provider'] ?? null, $validated['model'] ?? null);

        try {
            $image = $images->generate($validated['prompt'], $validated['size'] ?? $sizes->bookSize(), [], 'playground');
        } finally {
            $usage->flush(null);
        }

        return response()->json([
            'dataUrl' => 'data:image/png;base64,'.base64_encode($image->bytes),
            'attempt' => $image->attempt,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function providerInfo(): array
    {
        $textProvider = (string) config('cubfable.ai.text_provider');
        $imageProvider = (string) config('cubfable.ai.image_provider');

        return [
            'textProvider' => $textProvider,
            'textModel' => (string) config("cubfable.ai.models.text.{$textProvider}", ''),
            'imageProvider' => $imageProvider,
            'imageModel' => (string) config("cubfable.ai.models.image.{$imageProvider}", ''),
        ];
    }
}
