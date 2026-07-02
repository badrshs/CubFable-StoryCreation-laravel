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
    | Registration
    |--------------------------------------------------------------------------
    */

    'registration_open' => (bool) env('REGISTRATION_OPEN', true),

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
            ],
        ],

        'keys' => [
            'openai' => env('OPENAI_API_KEY', ''),
            'gemini' => env('GEMINI_API_KEY', ''),
            'openrouter' => env('OPENROUTER_API_KEY', ''),
            'flow' => env('FLOW_IMAGE_API_KEY', ''),
        ],

        /*
         * The local flow-image gateway (browser-driven Grok Imagine or Google
         * Flow). Images only; select it with IMAGE_PROVIDER=flow.
         */
        'flow_base_url' => env('FLOW_IMAGE_BASE_URL', 'http://127.0.0.1:8787'),

        /*
         * Some image models cap how many reference images a request may
         * carry (Grok Imagine accepts 3). 0 means unlimited. References are
         * ordered most-important-first, so truncation drops the least
         * important ones.
         */
        'max_image_references' => (int) env('IMAGE_MAX_REFERENCES', 0),

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
