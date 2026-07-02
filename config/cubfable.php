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
            ],
        ],

        'keys' => [
            'openai' => env('OPENAI_API_KEY', ''),
            'gemini' => env('GEMINI_API_KEY', ''),
            'openrouter' => env('OPENROUTER_API_KEY', ''),
        ],

        /*
         * Some image models cap how many reference images a request may
         * carry (Grok Imagine accepts 3). 0 means unlimited. References are
         * ordered most-important-first (character sheet, then the hero's
         * photo), so truncation drops the least important ones.
         */
        'max_image_references' => (int) env('IMAGE_MAX_REFERENCES', 0),

        'openai_base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

];
