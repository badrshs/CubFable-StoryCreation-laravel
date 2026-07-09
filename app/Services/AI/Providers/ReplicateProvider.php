<?php

namespace App\Services\AI\Providers;

use App\Exceptions\ImageContentRejectedException;
use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\Contracts\SupportsImageGroups;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use App\Services\AI\UsageEvent;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Replicate (images only). Different Replicate models take different input
 * shapes (flux-kontext wants a single `input_image` + `aspect_ratio`;
 * Seedream wants an `image_input` ARRAY + exact width/height; a silently
 * ignored field means the reference never reaches the model), so the
 * provider fetches each model's input schema once and adapts. References
 * are uploaded through Replicate's Files API first (data URIs only carry
 * small files); predictions run with Prefer: wait and are polled to
 * completion when they outlast the wait window.
 */
class ReplicateProvider implements AiProvider, SupportsImageGroups
{
    /**
     * Field names Replicate models use for reference images, in preference
     * order.
     */
    private const REFERENCE_KEYS = ['image_input', 'input_image', 'image'];

    private const int POLL_INTERVAL_SECONDS = 3;

    private const int POLL_TIMEOUT_SECONDS = 300;

    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        throw new RuntimeException('The replicate image provider generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.replicate');

        $input = $this->buildInput($model, $prompt, $size, $references);
        $prediction = $this->predict($model, $input);
        $bytes = $this->extractImage($prediction);

        $this->recordUsage($model, 1);

        return $bytes;
    }

    /**
     * Whether the configured model exposes Seedream-style sequential
     * generation (several images as one coherent set).
     */
    public function supportsImageGroups(): bool
    {
        $model = (string) config('cubfable.ai.models.image.replicate');

        return isset($this->inputSchema($model)['sequential_image_generation']);
    }

    /**
     * Generate up to $count images as ONE coherent set: same characters,
     * same style across every image, in scene order. The model may return
     * fewer; callers fill the remainder per-image.
     *
     * @param  list<ImageReference>  $references
     * @return list<string>
     */
    public function imageGroup(string $prompt, string $size, array $references, int $count): array
    {
        $model = (string) config('cubfable.ai.models.image.replicate');

        if (! $this->supportsImageGroups()) {
            throw new RuntimeException("Replicate model {$model} does not support group generation.");
        }

        // Total images (inputs + outputs) may not exceed 15.
        $cap = 15 - count($references);

        if ($count > $cap || $count < 1) {
            throw new RuntimeException("A group of {$count} images with ".count($references)." reference(s) exceeds the model's 15-image budget.");
        }

        $input = $this->buildInput($model, $prompt, $size, $references);
        $input['sequential_image_generation'] = 'auto';
        $input['max_images'] = $count;

        $prediction = $this->predict($model, $input);
        $images = $this->extractImages($prediction);

        $this->recordUsage($model, count($images));

        return $images;
    }

    /**
     * Create a prediction and wait it out to a completed state.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function predict(string $model, array $input): array
    {
        $response = $this->withThrottleRetry(fn () => Http::timeout(180)
            ->withToken($this->apiKey())
            ->withHeaders(['Prefer' => 'wait'])
            ->post($this->baseUrl()."/v1/models/{$model}/predictions", ['input' => $input]));

        if ($response->failed()) {
            $this->throwForFailure("Replicate prediction failed ({$response->status()})", $response->body());
        }

        return $this->awaitPrediction((array) $response->json());
    }

    private function recordUsage(string $model, int $images): void
    {
        $this->usage->record(new UsageEvent(
            kind: 'image',
            provider: 'replicate',
            model: $model,
            promptTokens: 0,
            outputTokens: 0,
            totalTokens: 0,
            costUsd: 0.04 * max(1, $images),
            estimated: true,
        ));
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        throw new RuntimeException('The replicate image provider generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * Shape the prediction input to what THIS model's schema actually
     * accepts. A field the model does not know is silently ignored by
     * Replicate, which for a wrongly named reference field means the model
     * quietly generates from text alone.
     *
     * @param  list<ImageReference>  $references
     * @return array<string, mixed>
     */
    private function buildInput(string $model, string $prompt, string $size, array $references): array
    {
        $schema = $this->inputSchema($model);
        $input = ['prompt' => $prompt];

        // References: find the field this model uses and match its type
        // (array models like Seedream take every reference; single-image
        // models like Kontext take the first).
        if ($references !== []) {
            $key = null;

            foreach (self::REFERENCE_KEYS as $candidate) {
                if (isset($schema[$candidate])) {
                    $key = $candidate;
                    break;
                }
            }

            // No schema available (fetch failed): fall back to the Kontext
            // convention rather than dropping the reference.
            $key ??= $schema === [] ? 'input_image' : null;

            if ($key !== null) {
                $urls = array_map(fn (ImageReference $reference): string => $this->uploadReference($reference), $references);
                $isArray = ($schema[$key]['type'] ?? null) === 'array' || $key === 'image_input';
                $input[$key] = $isArray ? $urls : $urls[0];
            } else {
                Log::warning("Replicate model {$model} exposes no known reference-image field; generating from text alone.");
            }
        }

        // Sizing: prefer the model's own aspect-ratio presets - models
        // enforce hidden constraints on custom pixel dimensions (Seedream
        // 4.5 rejects anything under ~1920x1920 total pixels with E006),
        // while ratio presets always produce valid sizes. Exact pixels only
        // when ratios are not offered at all.
        if (isset($schema['aspect_ratio']) || $schema === []) {
            $allowedRatios = isset($schema['aspect_ratio']['enum']) && is_array($schema['aspect_ratio']['enum'])
                ? $schema['aspect_ratio']['enum']
                : null;
            $input['aspect_ratio'] = $this->aspectRatio($size, $allowedRatios);

            // Prefer the model's largest resolution: side-by-side tests showed
            // the top tier commits to the requested art style far more
            // decisively than smaller ones, which kept reference faces
            // near-photographic. Different models name this field differently
            // (Seedream uses "size", Nano Banana uses "resolution") and each
            // lists its own tiers, so honour whichever the schema exposes -
            // leaving it unset lets the model default to its lowest tier
            // (Nano Banana 2 defaults to 1K), which looks washed out.
            foreach (['size', 'resolution'] as $resolutionField) {
                if (isset($schema[$resolutionField])) {
                    $input[$resolutionField] = $this->preferredSize($schema[$resolutionField]);
                }
            }
        } elseif (isset($schema['width'], $schema['height'])) {
            [$input['width'], $input['height']] = $this->dimensions($size);
        }

        if (isset($schema['output_format']) || $schema === []) {
            $input['output_format'] = 'png';
        }

        return $input;
    }

    /**
     * The model's input schema properties, fetched once and cached. An empty
     * array means the schema could not be read; callers fall back to the
     * Kontext-style conventions then.
     *
     * @return array<string, mixed>
     */
    private function inputSchema(string $model): array
    {
        return Cache::remember("replicate.input-schema.{$model}", now()->addHour(), function () use ($model): array {
            $response = Http::timeout(30)
                ->withToken($this->apiKey())
                ->get($this->baseUrl()."/v1/models/{$model}");

            if ($response->failed()) {
                Log::warning("Replicate model schema fetch failed for {$model} ({$response->status()}); using default input conventions.");

                return [];
            }

            $schemas = $response->json('latest_version.openapi_schema.components.schemas');
            $properties = is_array($schemas) ? ($schemas['Input']['properties'] ?? null) : null;

            if (! is_array($properties)) {
                return [];
            }

            return $this->resolveEnumReferences($properties, $schemas);
        });
    }

    /**
     * Inline enum constraints that Replicate exposes as separate schema
     * components referenced from a property via allOf/$ref. Without this the
     * property only carries a default, so callers cannot tell which values
     * the model actually accepts (e.g. size 2K/3K but not 4K).
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $schemas
     * @return array<string, mixed>
     */
    private function resolveEnumReferences(array $properties, array $schemas): array
    {
        foreach ($properties as $name => $definition) {
            if (! is_array($definition) || isset($definition['enum'])) {
                continue;
            }

            $refs = match (true) {
                isset($definition['allOf']) && is_array($definition['allOf']) => $definition['allOf'],
                isset($definition['$ref']) => [$definition],
                default => [],
            };

            foreach ($refs as $ref) {
                $target = $this->dereference($ref['$ref'] ?? null, $schemas);

                if (isset($target['enum']) && is_array($target['enum'])) {
                    $properties[$name]['enum'] = $target['enum'];
                    $properties[$name]['type'] ??= $target['type'] ?? 'string';
                    break;
                }
            }
        }

        return $properties;
    }

    /**
     * Resolve a local "#/components/schemas/Name" pointer to its component.
     *
     * @param  array<string, mixed>  $schemas
     * @return array<string, mixed>|null
     */
    private function dereference(?string $ref, array $schemas): ?array
    {
        if ($ref === null || ! str_starts_with($ref, '#/components/schemas/')) {
            return null;
        }

        $name = substr($ref, strlen('#/components/schemas/'));

        return is_array($schemas[$name] ?? null) ? $schemas[$name] : null;
    }

    /**
     * The largest resolution tier the model lists, preferring quality. Falls
     * back to 4K when the schema carries no enum (older Seedream models that
     * accept it), matching the previous behaviour.
     *
     * @param  array<string, mixed>  $sizeSchema
     */
    private function preferredSize(array $sizeSchema): string
    {
        $allowed = isset($sizeSchema['enum']) && is_array($sizeSchema['enum'])
            ? $sizeSchema['enum']
            : null;

        foreach (['4K', '3K', '2K', '1K'] as $tier) {
            if ($allowed === null || in_array($tier, $allowed, true)) {
                return $tier;
            }
        }

        return isset($allowed[0]) && is_string($allowed[0]) ? $allowed[0] : '4K';
    }

    /**
     * Push the reference file to Replicate's Files API and return the URL the
     * prediction input can point at (data URIs only work for tiny files).
     */
    private function uploadReference(ImageReference $reference): string
    {
        $bytes = Storage::disk('public')->get($reference->path);

        if ($bytes === null) {
            throw new RuntimeException("Reference image [{$reference->path}] could not be read.");
        }

        $response = $this->withThrottleRetry(fn () => Http::timeout(120)
            ->withToken($this->apiKey())
            ->attach('content', $bytes, basename($reference->path))
            ->post($this->baseUrl().'/v1/files'));

        if ($response->failed()) {
            throw new RuntimeException("Replicate file upload failed ({$response->status()}): {$response->body()}");
        }

        $url = (string) $response->json('urls.get');

        if ($url === '') {
            throw new RuntimeException('Replicate returned no file URL: '.$response->body());
        }

        return $url;
    }

    /**
     * Poll a prediction that outlasted the Prefer: wait window.
     *
     * @param  array<string, mixed>  $prediction
     * @return array<string, mixed>
     */
    private function awaitPrediction(array $prediction): array
    {
        $deadline = time() + self::POLL_TIMEOUT_SECONDS;

        while (in_array((string) ($prediction['status'] ?? ''), ['starting', 'processing', 'queued'], true)) {
            if (time() >= $deadline) {
                throw new RuntimeException('Replicate prediction did not complete within '.self::POLL_TIMEOUT_SECONDS.' seconds.');
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();

            $pollUrl = (string) ($prediction['urls']['get'] ?? ($this->baseUrl().'/v1/predictions/'.$prediction['id']));
            $response = $this->withThrottleRetry(fn () => Http::timeout(60)->withToken($this->apiKey())->get($pollUrl));

            if ($response->failed()) {
                throw new RuntimeException("Replicate prediction poll failed ({$response->status()}): {$response->body()}");
            }

            $prediction = (array) $response->json();
        }

        if ((string) ($prediction['status'] ?? '') !== 'succeeded') {
            $this->throwForFailure('Replicate prediction '.(string) ($prediction['status'] ?? 'failed'), (string) json_encode($prediction['error'] ?? $prediction));
        }

        return $prediction;
    }

    /**
     * Kontext outputs a single image URI; other models may return an array.
     *
     * @param  array<string, mixed>  $prediction
     */
    private function extractImage(array $prediction): string
    {
        $output = $prediction['output'] ?? null;
        $url = is_array($output) ? (string) ($output[0] ?? '') : (string) $output;

        $bytes = $this->downloadOutput($url);

        if ($bytes === null) {
            throw new RuntimeException('Replicate returned no usable image output: '.json_encode($output));
        }

        return $bytes;
    }

    /**
     * Every image of a grouped prediction, in output order.
     *
     * @param  array<string, mixed>  $prediction
     * @return list<string>
     */
    private function extractImages(array $prediction): array
    {
        $output = $prediction['output'] ?? null;
        $urls = is_array($output) ? $output : [$output];
        $images = [];

        foreach ($urls as $url) {
            $bytes = $this->downloadOutput((string) $url);

            if ($bytes !== null) {
                $images[] = $bytes;
            }
        }

        if ($images === []) {
            throw new RuntimeException('Replicate returned no usable image output: '.json_encode($output));
        }

        return $images;
    }

    private function downloadOutput(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            return null;
        }

        $download = Http::timeout(120)->get($url);

        return $download->successful() && $download->body() !== '' ? $download->body() : null;
    }

    /**
     * Accounts under $5 credit get throttled to 1 request per burst; a 429
     * is a "wait retry_after seconds" instruction, not a failure. Retry a
     * few times, honoring the server's own delay.
     *
     * @param  callable(): Response  $send
     */
    private function withThrottleRetry(callable $send): Response
    {
        $response = $send();

        for ($attempt = 1; $attempt <= 4 && $response->status() === 429; $attempt++) {
            $delay = (int) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 10);
            $delay = min(30, max(2, $delay + 1));

            Log::info("Replicate throttled this request (429); waiting {$delay}s before retry {$attempt}/4.");
            Sleep::for($delay)->seconds();

            $response = $send();
        }

        return $response;
    }

    /**
     * Route content-safety refusals to the rephrase ladder; everything else
     * is a plain failure.
     */
    private function throwForFailure(string $context, string $detail): never
    {
        if (preg_match('/nsfw|sensitive|moderat|content[_ ]policy|flagged|unsafe|safety/i', $detail) === 1) {
            throw new ImageContentRejectedException("{$context}: {$detail}");
        }

        throw new RuntimeException("{$context}: {$detail}");
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $size): array
    {
        if (preg_match('/^(\d+)x(\d+)$/', $size, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [1024, 1024];
    }

    /**
     * The closest supported aspect ratio for a WxH size string. When the model
     * lists its own accepted ratios, stay inside that set - Seedream 5 lite,
     * for one, omits 4:5/5:4/9:21 and rejects them with a 422.
     *
     * @param  list<string>|null  $allowed
     */
    private function aspectRatio(string $size, ?array $allowed = null): string
    {
        $supported = [
            '1:1' => 1.0, '16:9' => 16 / 9, '9:16' => 9 / 16, '4:3' => 4 / 3, '3:4' => 3 / 4,
            '3:2' => 3 / 2, '2:3' => 2 / 3, '4:5' => 4 / 5, '5:4' => 5 / 4, '21:9' => 21 / 9, '9:21' => 9 / 21,
        ];

        if ($allowed !== null) {
            $constrained = array_filter(
                $supported,
                fn (string $label): bool => in_array($label, $allowed, true),
                ARRAY_FILTER_USE_KEY,
            );

            if ($constrained !== []) {
                $supported = $constrained;
            }
        }

        $fallback = (string) array_key_first($supported);

        if (preg_match('/^(\d+)x(\d+)$/', $size, $matches) !== 1) {
            return array_key_exists('1:1', $supported) ? '1:1' : $fallback;
        }

        $ratio = (int) $matches[1] / max(1, (int) $matches[2]);

        $best = $fallback;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($supported as $label => $value) {
            $distance = abs($value - $ratio);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $label;
            }
        }

        return $best;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('cubfable.ai.replicate_base_url'), '/');
    }

    private function apiKey(): string
    {
        $key = (string) config('cubfable.ai.keys.replicate');

        if ($key === '') {
            throw new RuntimeException('REPLICATE_API_TOKEN is not set.');
        }

        return $key;
    }
}
