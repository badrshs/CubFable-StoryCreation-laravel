<?php

namespace App\Services\Prompts;

use App\Models\Character;
use Illuminate\Support\Facades\Storage;

/**
 * The engine policy for identity material: how many reference images can
 * actually travel with one request, whether the hero anchors on a generated
 * character sheet, and whether a stored photo is usable at all. Both
 * pipelines and every prompt composer consult this one class so they can
 * never disagree.
 */
class ReferencePolicy
{
    /**
     * How many reference images can actually travel with one request, so
     * prompts only describe characters whose reference cannot be sent.
     * Null means unlimited; 0 means the engine takes no references at all
     * (google-flow), which switches identity to text descriptions. Browser
     * Grok Imagine carries exactly one and xAI Grok three; other providers
     * follow the configurable cap.
     */
    public function budget(): ?int
    {
        $provider = (string) config('cubfable.ai.image_provider');

        if ($provider === 'flow') {
            $model = (string) config('cubfable.ai.models.image.flow');

            return str_contains($model, 'google') ? 0 : 1;
        }

        $cap = (int) config('cubfable.ai.max_image_references', 0);

        if ($provider === 'grok') {
            return $cap > 0 ? min($cap, 3) : 3;
        }

        // PiAPI's Kontext edit takes exactly one source image.
        if ($provider === 'piapi') {
            return 1;
        }

        // Replicate depends on the model: Seedream and Nano Banana read a
        // multi-image array (14+), so every photographed cast member's photo
        // can travel; Kontext-style editors take exactly one source image.
        if ($provider === 'replicate') {
            $model = strtolower((string) config('cubfable.ai.models.image.replicate'));

            $multiImage = str_contains($model, 'seedream') || str_contains($model, 'nano-banana');

            return $multiImage ? 6 : 1;
        }

        return $cap > 0 ? $cap : null;
    }

    /**
     * Whether the hero's identity anchors on a generated character sheet.
     * In photo mode the original upload is the reference instead, so a
     * sheet is only worth making when there is no photo to lean on - and an
     * engine that cannot take references at all (google-flow) never gets a
     * sheet, because there is nothing to attach it to; identity comes from
     * the text descriptions instead.
     */
    public function anchorsWithSheet(Character $main): bool
    {
        if ($this->budget() === 0) {
            return false;
        }

        $preference = strtolower(trim((string) config('cubfable.ai.identity_reference', 'sheet')));

        return ! ($preference === 'photo' && $this->hasUsablePhoto($main));
    }

    /**
     * True when a stored photo is a usable reference image on the public disk.
     */
    public function hasUsablePhoto(Character $character): bool
    {
        return $character->photo_path !== null
            && $character->photo_path !== ''
            && Storage::disk('public')->exists($character->photo_path);
    }
}
