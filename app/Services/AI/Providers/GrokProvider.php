<?php

namespace App\Services\AI\Providers;

use App\Exceptions\ImageContentRejectedException;
use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use App\Services\AI\UsageEvent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * xAI's native Grok Imagine API. Much cheaper than routing the same model
 * through OpenRouter (the standard model is not offered there at all):
 * flat per-image pricing, JSON requests, references as inline data URIs
 * (at most three per request). Images only; text stays on the API text
 * providers.
 */
class GrokProvider implements AiProvider
{
    /**
     * Grok Imagine accepts at most three source images per edit request.
     */
    private const int MAX_REFERENCES = 3;

    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        throw new RuntimeException('The grok image provider generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.grok');
        $references = array_slice($references, 0, self::MAX_REFERENCES);

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => 'b64_json',
            'n' => 1,
        ];

        $endpoint = '/images/generations';

        if ($references !== []) {
            $endpoint = '/images/edits';
            $sources = array_map(
                fn (ImageReference $reference): array => ['type' => 'image_url', 'url' => $reference->dataUrl()],
                $references,
            );
            $payload['image'] = count($sources) === 1 ? $sources[0] : $sources;
        }

        $response = Http::timeout(180)
            ->withToken($this->apiKey())
            ->post($this->baseUrl().$endpoint, $payload);

        if ($response->status() === 400 && preg_match('/content[_ ]policy|moderat|prohibited|unsafe/i', $response->body()) === 1) {
            throw new ImageContentRejectedException("Grok rejected the prompt: {$response->json('error.message')}");
        }

        if ($response->failed()) {
            throw new RuntimeException("Grok image error ({$response->status()}): {$response->body()}");
        }

        $bytes = $this->extractImage($response->json('data.0') ?? []);

        // Flat per-image pricing; standard and quality are the only tiers.
        $this->usage->record(new UsageEvent(
            kind: 'image',
            provider: 'grok',
            model: $model,
            promptTokens: 0,
            outputTokens: 0,
            totalTokens: 0,
            costUsd: str_contains($model, 'quality') ? 0.05 : 0.02,
            estimated: true,
        ));

        return $bytes;
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        throw new RuntimeException('The grok image provider generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * The image arrives inline as b64_json, or as a URL to download.
     *
     * @param  array<string, mixed>  $item
     */
    private function extractImage(array $item): string
    {
        $base64 = $item['b64_json'] ?? null;

        if (is_string($base64) && $base64 !== '') {
            $bytes = base64_decode($base64, true);

            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }

        $url = $item['url'] ?? null;

        if (is_string($url) && str_starts_with($url, 'http')) {
            $download = Http::timeout(120)->get($url);

            if ($download->successful() && $download->body() !== '') {
                return $download->body();
            }
        }

        throw new RuntimeException('Grok returned no usable image data.');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('cubfable.ai.grok_base_url'), '/');
    }

    private function apiKey(): string
    {
        $key = (string) config('cubfable.ai.keys.grok');

        if ($key === '') {
            throw new RuntimeException('XAI_API_KEY is not set.');
        }

        return $key;
    }
}
