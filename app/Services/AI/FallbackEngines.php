<?php

namespace App\Services\AI;

/**
 * The ordered engine chain tried when the active image engine refuses a
 * prompt on content grounds. Parsed from the admin-editable setting
 * (comma-separated provider:model entries); entries that name an unknown
 * provider or duplicate the currently active engine are dropped.
 */
class FallbackEngines
{
    private const PROVIDERS = ['openai', 'gemini', 'openrouter', 'flow', 'grok', 'piapi', 'replicate'];

    /**
     * @return list<array{provider: string, model: string}>
     */
    public function chain(): array
    {
        $raw = (string) config('cubfable.ai.fallback_engines', '');
        $activeProvider = strtolower(trim((string) config('cubfable.ai.image_provider')));
        $activeModel = strtolower(trim((string) config("cubfable.ai.models.image.{$activeProvider}")));

        $chain = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            [$provider, $model] = array_pad(explode(':', $entry, 2), 2, '');
            $provider = strtolower(trim($provider));
            $model = trim($model);

            if (! in_array($provider, self::PROVIDERS, true) || $model === '') {
                continue;
            }

            if ($provider === $activeProvider && strtolower($model) === $activeModel) {
                continue;
            }

            $chain[] = ['provider' => $provider, 'model' => $model];
        }

        return $chain;
    }
}
