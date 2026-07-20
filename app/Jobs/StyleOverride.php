<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * Apply a per-run art-style override inside a queued job: the prompt composer
 * reads config('cubfable.ai.style_override') for this worker process only
 * (queue:listen boots a fresh process per job), so one image can be drawn in
 * a different style without touching the book's stored style or any other
 * image.
 */
class StyleOverride
{
    public static function apply(?string $style): void
    {
        if ($style === null || $style === '') {
            return;
        }

        config()->set('cubfable.ai.style_override', $style);

        Log::info('Style override for this run.', ['art_style' => $style]);
    }
}
