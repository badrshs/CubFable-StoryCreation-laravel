<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * Apply a per-run image engine override inside a queued job: the config is
 * changed for this worker process only (queue:listen boots a fresh process
 * per job), so one regeneration can try a different provider/model without
 * touching the stored settings.
 */
class EngineOverride
{
    public static function apply(?string $provider, ?string $model): void
    {
        if ($provider === null || $provider === '') {
            return;
        }

        config()->set('cubfable.ai.image_provider', $provider);

        // An explicit override beats every configured engine split (e.g.
        // the dedicated cover engine): the admin picked this one on purpose.
        config()->set('cubfable.ai.engine_override_active', true);

        if ($model !== null && $model !== '') {
            config()->set("cubfable.ai.models.image.{$provider}", $model);
        }

        Log::info('Engine override for this run.', array_filter([
            'image_provider' => $provider,
            'image_model' => $model,
        ]));
    }
}
