<?php

namespace App\Services\AI\Replicate;

/**
 * The curated Replicate engine lineup: every entry was verified against the
 * model's live input schema and pricing page, so each one is a switch-to-able
 * engine whose parameters are known to be correct. Models not listed here
 * still work through the provider's schema-fetch fallback; they just lose the
 * verified guarantees (exact reference field, tiered cost, group support).
 */
class ReplicateModelCatalog
{
    /**
     * @return list<ReplicateModel>
     */
    public function all(): array
    {
        return [
            new ReplicateModel(
                slug: 'bytedance/seedream-5-pro',
                label: 'Seedream 5 Pro',
                description: 'ByteDance flagship; up to 10 reference images for strong cast consistency.',
                referenceField: 'image_input',
                referencesAreArray: true,
                maxReferences: 10,
                aspectRatios: ['1:1', '4:3', '3:4', '16:9', '9:16', '3:2', '2:3', '21:9'],
                sizeField: 'size',
                sizeTiers: ['1K', '2K'],
                outputFormat: 'png',
                costPerImage: ['1K' => 0.045, '2K' => 0.09],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'bytedance/seedream-4.5',
                label: 'Seedream 4.5',
                description: 'Up to 14 reference images plus sequential group generation; 4K capable.',
                referenceField: 'image_input',
                referencesAreArray: true,
                maxReferences: 14,
                aspectRatios: ['1:1', '4:3', '3:4', '4:5', '5:4', '16:9', '9:16', '3:2', '2:3', '21:9', '9:21'],
                sizeField: 'size',
                sizeTiers: ['2K', '4K'],
                outputFormat: null,
                costPerImage: ['' => 0.04],
                supportsGroups: true,
            ),
            new ReplicateModel(
                slug: 'bytedance/seedream-5-lite',
                label: 'Seedream 5 Lite',
                description: 'Budget Seedream; up to 14 reference images and group generation.',
                referenceField: 'image_input',
                referencesAreArray: true,
                maxReferences: 14,
                aspectRatios: ['1:1', '4:3', '3:4', '16:9', '9:16', '3:2', '2:3', '21:9'],
                sizeField: 'size',
                sizeTiers: ['2K', '3K'],
                outputFormat: 'png',
                costPerImage: ['' => 0.035],
                supportsGroups: true,
            ),
            new ReplicateModel(
                slug: 'google/nano-banana-2',
                label: 'Nano Banana 2',
                description: 'Gemini image model; up to 14 reference images, strong instruction following.',
                referenceField: 'image_input',
                referencesAreArray: true,
                maxReferences: 14,
                aspectRatios: ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                sizeField: 'resolution',
                sizeTiers: ['1K', '2K', '4K'],
                outputFormat: 'png',
                costPerImage: ['1K' => 0.067, '2K' => 0.101, '4K' => 0.151],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'google/nano-banana-pro',
                label: 'Nano Banana Pro',
                description: 'Gemini 3 Pro Image; best-in-class fusion of up to 14 references, 4K capable.',
                referenceField: 'image_input',
                referencesAreArray: true,
                maxReferences: 14,
                aspectRatios: ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'],
                sizeField: 'resolution',
                sizeTiers: ['1K', '2K', '4K'],
                outputFormat: 'png',
                costPerImage: ['1K' => 0.15, '2K' => 0.15, '4K' => 0.30],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'black-forest-labs/flux-kontext-pro',
                label: 'Flux Kontext Pro',
                description: 'Single-reference editing model; strong identity lock from one sheet or photo.',
                referenceField: 'input_image',
                referencesAreArray: false,
                maxReferences: 1,
                aspectRatios: ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '4:5', '5:4', '21:9', '9:21'],
                sizeField: null,
                sizeTiers: [],
                outputFormat: 'png',
                costPerImage: ['' => 0.04],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'black-forest-labs/flux-kontext-max',
                label: 'Flux Kontext Max',
                description: 'Premium Kontext editing; better typography and precision, single reference.',
                referenceField: 'input_image',
                referencesAreArray: false,
                maxReferences: 1,
                aspectRatios: ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3', '4:5', '5:4', '21:9', '9:21'],
                sizeField: null,
                sizeTiers: [],
                outputFormat: 'png',
                costPerImage: ['' => 0.08],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'black-forest-labs/flux-2-pro',
                label: 'Flux 2 Pro',
                description: 'FLUX.2 with up to 8 reference images; strong editing and identity carry-over.',
                referenceField: 'input_images',
                referencesAreArray: true,
                maxReferences: 8,
                aspectRatios: ['1:1', '16:9', '3:2', '2:3', '4:5', '5:4', '9:16', '3:4', '4:3'],
                sizeField: 'resolution',
                sizeTiers: ['1 MP', '2 MP', '4 MP'],
                outputFormat: 'png',
                costPerImage: ['1 MP' => 0.015, '2 MP' => 0.03, '4 MP' => 0.06],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'black-forest-labs/flux-2-max',
                label: 'Flux 2 Max',
                description: 'Premium FLUX.2 with up to 8 reference images; best Flux quality and typography.',
                referenceField: 'input_images',
                referencesAreArray: true,
                maxReferences: 8,
                aspectRatios: ['1:1', '16:9', '3:2', '2:3', '4:5', '5:4', '9:16', '3:4', '4:3'],
                sizeField: 'resolution',
                sizeTiers: ['1 MP', '2 MP', '4 MP'],
                outputFormat: 'png',
                costPerImage: ['1 MP' => 0.03, '2 MP' => 0.06, '4 MP' => 0.12],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'black-forest-labs/flux-1.1-pro',
                label: 'Flux 1.1 Pro',
                description: 'Fast text-to-image; the reference only guides composition, not identity.',
                referenceField: 'image_prompt',
                referencesAreArray: false,
                maxReferences: 1,
                aspectRatios: ['1:1', '16:9', '3:2', '2:3', '4:5', '5:4', '9:16', '3:4', '4:3'],
                sizeField: null,
                sizeTiers: [],
                outputFormat: 'png',
                costPerImage: ['' => 0.04],
                supportsGroups: false,
            ),
            new ReplicateModel(
                slug: 'ideogram-ai/ideogram-v3-turbo',
                label: 'Ideogram v3 Turbo',
                description: 'Illustration styles and text rendering; takes NO character references.',
                referenceField: null,
                referencesAreArray: false,
                maxReferences: 0,
                aspectRatios: ['1:1', '16:9', '9:16', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4'],
                sizeField: null,
                sizeTiers: [],
                outputFormat: null,
                costPerImage: ['' => 0.03],
                supportsGroups: false,
                staticParams: ['magic_prompt_option' => 'Off'],
            ),
            new ReplicateModel(
                slug: 'recraft-ai/recraft-v3',
                label: 'Recraft v3',
                description: 'Kid-friendly illustration styles; takes NO character references.',
                referenceField: null,
                referencesAreArray: false,
                maxReferences: 0,
                aspectRatios: ['1:1', '4:3', '3:4', '3:2', '2:3', '16:9', '9:16', '4:5', '5:4'],
                sizeField: null,
                sizeTiers: [],
                outputFormat: null,
                costPerImage: ['' => 0.04],
                supportsGroups: false,
            ),
        ];
    }

    public function find(string $slug): ?ReplicateModel
    {
        $slug = strtolower(trim($slug));

        foreach ($this->all() as $model) {
            if ($model->slug === $slug) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The engine list the admin UI renders (settings presets, per-book
     * override dropdown, playground picker).
     *
     * @return list<array{provider: string, model: string, label: string, description: string, cost: string, costDetail: string, supportsGroups: bool, maxReferences: int}>
     */
    public function options(): array
    {
        return array_map(fn (ReplicateModel $model): array => [
            'provider' => 'replicate',
            'model' => $model->slug,
            'label' => $model->label,
            'description' => $model->description,
            'cost' => $model->costLabel(),
            'costDetail' => $model->costDetail(),
            'supportsGroups' => $model->supportsGroups,
            'maxReferences' => $model->maxReferences,
        ], $this->all());
    }
}
