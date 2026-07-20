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
     * Which payment provider new checkouts use: 'stripe' or 'paddle'.
     * Existing orders always reconcile against the provider they were
     * created with, so switching never strands an in-flight payment.
     */
    'payment_provider' => strtolower((string) env('PAYMENT_PROVIDER', 'stripe')),

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
         * How book art sits on the page: 'overlay' bleeds the illustration
         * across the whole page with the story text on a translucent panel
         * (crops the least on portrait pages), 'crop' scales each image to
         * fill a landscape band (edges are cropped when the ratios differ),
         * 'full' shows the whole image, letterboxed when needed.
         */
        'image_fit' => (string) env('PDF_IMAGE_FIT', 'overlay'),

        /*
         * Story fonts by language (settings pdf_font_default and
         * pdf_font_<lang>). Each value is a bundled face slug (scheherazade,
         * harmattan, aref-ruqaa, almarai, amiri, baloo, cormorant,
         * patrick-hand, luckiest-guy) or a Google Fonts family name
         * downloaded at build time. A language without a value inherits the
         * default; empty/auto default keeps the automatic per-style,
         * per-script behavior.
         */
        'fonts' => [
            'default' => (string) env('PDF_FONT_DEFAULT', ''),
        ],
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
    | Media storage
    |--------------------------------------------------------------------------
    |
    | Which filesystem disks hold user media. 'disk' is the public/CDN store
    | for generated covers and page art (set MEDIA_DISK=r2 in production).
    | 'private_disk' holds uploaded reference photos of children, served only
    | through short-lived signed URLs (set PRIVATE_MEDIA_DISK=r2_private). Both
    | default to local disks so development needs no cloud credentials.
    |
    */

    'media' => [
        'disk' => (string) env('MEDIA_DISK', 'public'),
        'private_disk' => (string) env('PRIVATE_MEDIA_DISK', 'local'),
        'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    'registration_open' => (bool) env('REGISTRATION_OPEN', true),

    /*
    |--------------------------------------------------------------------------
    | Abuse protection
    |--------------------------------------------------------------------------
    |
    | Device/IP identity recording and free-benefit gating. Accounts sharing
    | a device cookie, browser fingerprint, or recent IP are treated as one
    | household when handing out free benefits; nothing is ever blocked, the
    | worst case is being routed to the payment page instead of a freebie.
    |
    */

    'abuse' => [
        /*
         * How long an IP overlap keeps counting as "same household" when
         * checking benefit grants. IPs are shared (families, offices,
         * carriers), so old overlaps must expire.
         */
        'ip_window_days' => (int) env('ABUSE_IP_WINDOW_DAYS', 30),

        /*
         * Minimum minutes between identity writes for the same user+device,
         * so the middleware does not hit the database on every request.
         */
        'record_throttle_minutes' => (int) env('ABUSE_RECORD_THROTTLE_MINUTES', 10),

        /*
         * Cookie names: cf_did is the server-issued long-lived device id
         * (httpOnly, encrypted); cf_fp is the client-computed browser
         * fingerprint (JS-set, exempt from cookie encryption).
         */
        'device_cookie' => 'cf_did',
        'fingerprint_cookie' => 'cf_fp',

        /*
         * Free CIDR lists used to flag VPN and datacenter IPs (X4BNet
         * lists_vpn project), downloaded by abuse:update-ip-lists into
         * storage/app/{path}. Flags are informational until a caller
         * consults them; missing lists mean "unknown", never an error.
         */
        'ip_lists' => [
            'path' => 'abuse',
            'vpn_url' => (string) env('ABUSE_VPN_LIST_URL', 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt'),
            'datacenter_url' => (string) env('ABUSE_DATACENTER_LIST_URL', 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt'),
        ],
    ],

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
         * identity reference, not book art. 3:4 matches the cover shelf and
         * reader frames exactly and nearly fills portrait PDF pages, so the
         * art survives every surface with minimal cropping.
         */
        'image_aspect_ratio' => (string) env('IMAGE_ASPECT_RATIO', '3:4'),

        /*
         * Some image models cap how many reference images a request may
         * carry (Grok Imagine accepts 3). 0 means unlimited. References are
         * ordered most-important-first, so truncation drops the least
         * important ones.
         */
        'max_image_references' => (int) env('IMAGE_MAX_REFERENCES', 0),

        /*
         * The engines tried, in order, when the main engine refuses an image
         * on content grounds. Comma-separated provider:model entries. Round
         * one runs the ORIGINAL prompt through the main engine and then this
         * chain; only when the whole chain refuses is the prompt rewritten
         * once and the chain run again. After two rounds the item is flagged
         * for admin review. Empty disables the chain (rewrite-only retry).
         * Content-refused attempts cost nothing on Replicate official models.
         */
        'fallback_engines' => (string) env('IMAGE_FALLBACK_ENGINES', 'replicate:bytedance/seedream-4.5,replicate:google/nano-banana-pro,replicate:black-forest-labs/flux-2-pro'),

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
