<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StoryLanguage;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Services\AI\ImageSizePolicy;
use App\Services\AI\Replicate\ReplicateModelCatalog;
use App\Services\AppSettings;
use App\Services\Pdf\PageSize;
use App\Services\Pdf\StorybookPdfBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    /**
     * The runtime settings form: every registered key with its effective
     * value, its env-backed default, and whether the DB overrides it.
     */
    public function index(AppSettings $settings, ReplicateModelCatalog $catalog): Response
    {
        return Inertia::render('admin/settings', [
            'settings' => $settings->all(),
            'pdfPageSizes' => PageSize::options(),
            'replicateEngines' => $catalog->options(),
            'imageAspectRatios' => ImageSizePolicy::selectableRatios(),
            'storyLanguages' => array_map(
                fn (StoryLanguage $language): array => ['code' => $language->value, 'label' => $language->label()],
                StoryLanguage::cases(),
            ),
            'bundledFaces' => StorybookPdfBuilder::bundledFaceKeys(),
        ]);
    }

    /**
     * Compose a real book's PDF at any preset size and stream it inline, so
     * the admin can compare sizes in a browser tab before saving one.
     * Nothing is persisted.
     */
    public function pdfPreview(Request $request, StorybookPdfBuilder $builder): HttpResponse
    {
        $validated = $request->validate([
            'bookId' => ['required', 'integer', 'exists:books,id'],
            'size' => ['required', Rule::in(PageSize::keys())],
            'variant' => ['required', 'in:home,print'],
            'fit' => ['nullable', 'in:crop,full,overlay'],
            'font' => ['nullable', 'string', 'max:120'],
        ]);

        $book = Book::query()->findOrFail((int) $validated['bookId']);

        // Preview any story face without saving it (request-scoped config):
        // the given spec is applied to this book's language.
        if (($validated['font'] ?? null) !== null && trim((string) $validated['font']) !== '') {
            config()->set('cubfable.pdf.fonts.'.$book->language, trim((string) $validated['font']));
        }

        $pdfBytes = $builder->build($book, $validated['variant'], $validated['size'], $validated['fit'] ?? null);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="pdf-preview-'.$validated['size'].'-'.$validated['variant'].'.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Persist runtime overrides. Values land in the settings table and shadow
     * the env config from the next boot (the queue picks them up per job).
     */
    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        // One optional story-font spec per language, plus the default.
        $fontRules = ['pdf_font_default' => ['nullable', 'string', 'max:120']];

        foreach (StoryLanguage::cases() as $language) {
            $fontRules["pdf_font_{$language->value}"] = ['nullable', 'string', 'max:120'];
        }

        $validated = $request->validate([...$fontRules,
            'text_provider' => ['required', 'in:openai,gemini,openrouter'],
            'image_provider' => ['required', 'in:openai,gemini,openrouter,flow,grok,piapi,replicate'],
            'text_model_openai' => ['required', 'string', 'max:120'],
            'text_model_gemini' => ['required', 'string', 'max:120'],
            'text_model_openrouter' => ['required', 'string', 'max:120'],
            'image_model_openai' => ['required', 'string', 'max:120'],
            'image_model_gemini' => ['required', 'string', 'max:120'],
            'image_model_openrouter' => ['required', 'string', 'max:120'],
            'image_model_flow' => ['required', 'string', 'max:120'],
            'image_model_grok' => ['required', 'string', 'max:120'],
            'image_model_piapi' => ['required', 'string', 'max:120'],
            'image_model_replicate' => ['required', 'string', 'max:120'],
            'vision_model_openrouter' => ['nullable', 'string', 'max:120'],
            'identity_reference' => ['required', 'in:sheet,photo'],
            'max_image_references' => ['required', 'integer', 'between:0,8'],
            'image_fallback_engines' => ['nullable', 'string', 'max:500', 'regex:/^\s*$|^\s*(openai|gemini|openrouter|flow|grok|piapi|replicate):[^,]+(\s*,\s*(openai|gemini|openrouter|flow|grok|piapi|replicate):[^,]+)*\s*$/i'],
            'image_group_generation' => ['required', 'boolean'],
            'image_quality' => ['required', 'in:standard,high,max'],
            'image_aspect_ratio' => ['required', Rule::in(ImageSizePolicy::selectableRatios())],
            'cover_image_provider' => ['nullable', 'in:openai,gemini,openrouter,flow,grok,piapi,replicate'],
            'cover_image_model' => ['nullable', 'string', 'max:200'],
            'pdf_page_size' => ['required', Rule::in(PageSize::keys())],
            'pdf_image_fit' => ['required', 'in:crop,full,overlay'],
            'photo_upload_quality' => ['required', 'in:original,optimized'],
            'price_cents' => ['required', 'integer', 'between:100,100000'],
            'price_currency' => ['required', 'in:eur,usd,gbp,try'],
            'registration_open' => ['required', 'boolean'],
            'pages_min' => ['required', 'integer', 'between:1,40', 'lte:pages_max'],
            'pages_max' => ['required', 'integer', 'between:1,40', 'gte:pages_min'],
        ]);

        // The vision override is optional; empty string means "follow text model".
        $validated['vision_model_openrouter'] = (string) ($validated['vision_model_openrouter'] ?? '');

        // The fallback chain is optional; empty disables engine fallback.
        $validated['image_fallback_engines'] = (string) ($validated['image_fallback_engines'] ?? '');

        // The cover engine is optional too; empty means "same as main engine".
        $validated['cover_image_provider'] = (string) ($validated['cover_image_provider'] ?? '');
        $validated['cover_image_model'] = (string) ($validated['cover_image_model'] ?? '');

        // Font specs are optional; empty means inherit (language -> default
        // -> automatic per-style face).
        foreach (array_keys($fontRules) as $fontKey) {
            $validated[$fontKey] = (string) ($validated[$fontKey] ?? '');
        }

        $settings->set($validated);

        return back();
    }
}
