<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    |
    | One-time price charged per storybook, in the smallest currency unit.
    |
    */

    'price_cents' => (int) env('PRICE_CENTS', 799),
    'price_currency' => strtolower((string) env('PRICE_CURRENCY', 'eur')),

    /*
    |--------------------------------------------------------------------------
    | PDF output
    |--------------------------------------------------------------------------
    |
    | The trim size every storybook PDF is composed at: one of the
    | App\Services\Pdf\PageSize preset keys, admin-overridable at runtime
    | through the pdf_page_size setting.
    |
    */

    'pdf' => [
        'page_size' => (string) env('PDF_PAGE_SIZE', 'square-210'),

        /*
         * How book art sits on the page: 'crop' scales each image to fill
         * its area (edges are cropped when the ratios differ), 'full' shows
         * the whole image, letterboxed on the page background when needed.
         */
        'image_fit' => (string) env('PDF_IMAGE_FIT', 'crop'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Character photo handling: 'original' uploads the untouched file (best
    | likeness for image models that stylize from the reference), while
    | 'optimized' downscales in the browser to 768px JPEG (smaller requests,
    | lower vision-token cost). Admin-overridable at runtime.
    |
    */

    'uploads' => [
        'photo_quality' => (string) env('PHOTO_UPLOAD_QUALITY', 'original'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    'registration_open' => (bool) env('REGISTRATION_OPEN', true),

    /*
    |--------------------------------------------------------------------------
    | Page limits
    |--------------------------------------------------------------------------
    |
    | Bounds for a story template's page count, enforced when templates are
    | created or edited in the admin area.
    |
    */

    'pages_min' => (int) env('PAGES_MIN', 4),
    'pages_max' => (int) env('PAGES_MAX', 10),

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Text and image generation can be routed to independent providers.
    | Vision (photo description) always follows the text provider.
    |
    */

    'ai' => [
        'text_provider' => env('TEXT_PROVIDER', 'openai'),
        'image_provider' => env('IMAGE_PROVIDER', 'openai'),

        'models' => [
            'text' => [
                'openai' => env('TEXT_MODEL_OPENAI', 'gpt-5.4'),
                'gemini' => env('TEXT_MODEL_GEMINI', 'gemini-2.5-flash'),
                'openrouter' => env('TEXT_MODEL_OPENROUTER', 'google/gemini-2.5-flash'),
            ],
            'image' => [
                'openai' => env('IMAGE_MODEL_OPENAI', 'gpt-image-1'),
                'gemini' => env('IMAGE_MODEL_GEMINI', 'gemini-2.5-flash-image'),
                'openrouter' => env('IMAGE_MODEL_OPENROUTER', 'google/gemini-2.5-flash-image'),
                'flow' => env('IMAGE_MODEL_FLOW', 'grok-imagine'),
                'grok' => env('IMAGE_MODEL_GROK', 'grok-imagine-image'),
                'piapi' => env('IMAGE_MODEL_PIAPI', 'Qubico/flux1-dev-advanced'),
                'replicate' => env('IMAGE_MODEL_REPLICATE', 'bytedance/seedream-5-pro'),
            ],
            /*
             * Photo description (vision) normally rides on the text model.
             * Set this when the text model cannot take image input (e.g.
             * DeepSeek), so vision stays on a multimodal model.
             */
            'vision' => [
                'openrouter' => env('VISION_MODEL_OPENROUTER', ''),
            ],
        ],

        'keys' => [
            'openai' => env('OPENAI_API_KEY', ''),
            'gemini' => env('GEMINI_API_KEY', ''),
            'openrouter' => env('OPENROUTER_API_KEY', ''),
            'flow' => env('FLOW_IMAGE_API_KEY', ''),
            'grok' => env('XAI_API_KEY', ''),
            'piapi' => env('PIAPI_API_KEY', ''),
            'replicate' => env('REPLICATE_API_TOKEN', ''),
        ],

        /*
         * xAI's native Grok Imagine API (images only): IMAGE_PROVIDER=grok.
         */
        'grok_base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),

        /*
         * The local flow-image gateway (browser-driven Grok Imagine or Google
         * Flow). Images only; select it with IMAGE_PROVIDER=flow.
         */
        'flow_base_url' => env('FLOW_IMAGE_BASE_URL', 'http://127.0.0.1:8787'),

        /*
         * PiAPI's Flux API (images only): IMAGE_PROVIDER=piapi. Reference
         * images run the subject-preserving Kontext edit task.
         */
        'piapi_base_url' => env('PIAPI_BASE_URL', 'https://api.piapi.ai'),

        /*
         * Replicate (images only): IMAGE_PROVIDER=replicate. Each cataloged
         * model (ReplicateModelCatalog) is a switchable engine; references
         * travel via Replicate's Files API.
         */
        'replicate_base_url' => env('REPLICATE_BASE_URL', 'https://api.replicate.com'),

        /*
         * Resolution tier requested from engines that offer one (Replicate
         * catalog models): standard picks the smallest tier, high the ~2K
         * sweet spot, max the largest the model lists. Higher tiers cost
         * more per image and commit harder to the art style.
         */
        'image_quality' => (string) env('IMAGE_QUALITY', 'high'),

        /*
         * A dedicated engine for the COVER only, so the one image that sells
         * the book can run on a pricier model than the pages. Empty provider
         * means the cover uses the main image engine; empty model means the
         * provider's configured model. An explicit per-run admin override
         * still wins over this.
         */
        'cover_image_provider' => (string) env('COVER_IMAGE_PROVIDER', ''),
        'cover_image_model' => (string) env('COVER_IMAGE_MODEL', ''),

        /*
         * The aspect ratio every page and cover is generated at. Engines
         * that only accept their own ratio presets get the closest one they
         * support. The character sheet stays portrait regardless; it is an
         * identity reference, not book art.
         */
        'image_aspect_ratio' => (string) env('IMAGE_ASPECT_RATIO', '9:16'),

        /*
         * Some image models cap how many reference images a request may
         * carry (Grok Imagine accepts 3). 0 means unlimited. References are
         * ordered most-important-first, so truncation drops the least
         * important ones.
         */
        'max_image_references' => (int) env('IMAGE_MAX_REFERENCES', 0),

        /*
         * Render all of a book's pages as ONE coherent set when the engine
         * supports it (Seedream's sequential generation). Off by default: the
         * shared group prompt is heavily clipped to fit the engine cap, so
         * character identity and scene order suffer compared to the full
         * per-page prompt. Page-by-page is the standard path.
         */
        'group_generation' => (bool) env('IMAGE_GROUP_GENERATION', false),

        /*
         * What anchors the hero's identity on cover/page images:
         * - sheet: generate one stylized character sheet from the photo and
         *   reference it everywhere (one photo-to-illustration jump).
         * - photo: reference the original uploaded photo directly on every
         *   image; the character sheet step is skipped when a photo exists.
         */
        'identity_reference' => env('IMAGE_IDENTITY_REFERENCE', 'sheet'),

        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

];
