<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-editable runtime settings. Each registered key shadows one env-backed
 * cubfable config path: a stored value overrides the config at boot (so every
 * existing consumer keeps reading config('cubfable.*') unchanged), and a key
 * with no stored value falls through to the env default. queue:listen boots a
 * fresh process per job, so generation picks up changes on the next job.
 */
class AppSettings
{
    private const CACHE_KEY = 'cubfable.settings';

    /**
     * Registered settings: key => [config path, cast]. Only these keys can be
     * stored or applied; anything else in the table is ignored.
     *
     * @var array<string, array{path: string, cast: 'string'|'int'|'bool'}>
     */
    private const REGISTRY = [
        'text_provider' => ['path' => 'cubfable.ai.text_provider', 'cast' => 'string'],
        'image_provider' => ['path' => 'cubfable.ai.image_provider', 'cast' => 'string'],
        'text_model_openai' => ['path' => 'cubfable.ai.models.text.openai', 'cast' => 'string'],
        'text_model_gemini' => ['path' => 'cubfable.ai.models.text.gemini', 'cast' => 'string'],
        'text_model_openrouter' => ['path' => 'cubfable.ai.models.text.openrouter', 'cast' => 'string'],
        'image_model_openai' => ['path' => 'cubfable.ai.models.image.openai', 'cast' => 'string'],
        'image_model_gemini' => ['path' => 'cubfable.ai.models.image.gemini', 'cast' => 'string'],
        'image_model_openrouter' => ['path' => 'cubfable.ai.models.image.openrouter', 'cast' => 'string'],
        'image_model_flow' => ['path' => 'cubfable.ai.models.image.flow', 'cast' => 'string'],
        'image_model_grok' => ['path' => 'cubfable.ai.models.image.grok', 'cast' => 'string'],
        'image_model_piapi' => ['path' => 'cubfable.ai.models.image.piapi', 'cast' => 'string'],
        'image_model_replicate' => ['path' => 'cubfable.ai.models.image.replicate', 'cast' => 'string'],
        'vision_model_openrouter' => ['path' => 'cubfable.ai.models.vision.openrouter', 'cast' => 'string'],
        'identity_reference' => ['path' => 'cubfable.ai.identity_reference', 'cast' => 'string'],
        'max_image_references' => ['path' => 'cubfable.ai.max_image_references', 'cast' => 'int'],
        'image_group_generation' => ['path' => 'cubfable.ai.group_generation', 'cast' => 'bool'],
        'pdf_page_size' => ['path' => 'cubfable.pdf.page_size', 'cast' => 'string'],
        'photo_upload_quality' => ['path' => 'cubfable.uploads.photo_quality', 'cast' => 'string'],
        'price_cents' => ['path' => 'cubfable.price_cents', 'cast' => 'int'],
        'price_currency' => ['path' => 'cubfable.price_currency', 'cast' => 'string'],
        'registration_open' => ['path' => 'cubfable.registration_open', 'cast' => 'bool'],
        'pages_min' => ['path' => 'cubfable.pages_min', 'cast' => 'int'],
        'pages_max' => ['path' => 'cubfable.pages_max', 'cast' => 'int'],
    ];

    /**
     * Override the env-backed config with every stored setting. Called from
     * AppServiceProvider::boot; a missing table (fresh install, mid-migration)
     * silently no-ops.
     */
    public function apply(): void
    {
        $stored = rescue(fn (): array => $this->stored(), [], false);

        foreach ($stored as $key => $value) {
            if (! isset(self::REGISTRY[$key])) {
                continue;
            }

            config()->set(self::REGISTRY[$key]['path'], $this->cast($value, self::REGISTRY[$key]['cast']));
        }
    }

    /**
     * The effective value per key (stored override or env-backed default),
     * plus whether it is overridden - what the admin settings page shows.
     *
     * @return array<string, array{value: mixed, default: mixed, overridden: bool}>
     */
    public function all(): array
    {
        $stored = rescue(fn (): array => $this->stored(), [], false);
        $out = [];

        foreach (self::REGISTRY as $key => $meta) {
            $default = config($meta['path']);
            $overridden = array_key_exists($key, $stored);

            $out[$key] = [
                'value' => $overridden ? $this->cast($stored[$key], $meta['cast']) : $default,
                'default' => $default,
                'overridden' => $overridden,
            ];
        }

        return $out;
    }

    /**
     * Store overrides for the given registered keys. A null value clears the
     * override so the env default takes effect again.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! isset(self::REGISTRY[$key])) {
                continue;
            }

            if ($value === null) {
                Setting::query()->where('key', $key)->delete();

                continue;
            }

            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->cast($value, self::REGISTRY[$key]['cast'])],
            );
        }

        Cache::forget(self::CACHE_KEY);
        $this->apply();
    }

    /**
     * Whether a key is registered as an editable setting.
     */
    public static function isRegistered(string $key): bool
    {
        return isset(self::REGISTRY[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }

    private function cast(mixed $value, string $cast): mixed
    {
        return match ($cast) {
            'int' => (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
            default => (string) $value,
        };
    }
}
