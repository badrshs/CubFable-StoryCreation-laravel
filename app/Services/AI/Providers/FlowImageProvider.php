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
 * The local flow-image gateway: an OpenAI-compatible image API that renders
 * through a real, logged-in browser session (grok.com/imagine or Google
 * Flow), so generations cost nothing beyond the consumer account. Blocking
 * calls take 30-180 seconds per image. Images only; text and vision stay on
 * the API providers.
 */
class FlowImageProvider implements AiProvider
{
    /**
     * The gateway drives one browser and generation is slow by design.
     */
    private const int TIMEOUT_SECONDS = 600;

    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        throw new RuntimeException('The flow-image gateway generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.flow');

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => 'b64_json',
            'n' => 1,
        ];

        // The gateway's composer accepts a single reference; ours are ordered
        // most-important-first (character sheet, then the hero photo).
        if ($references !== []) {
            $payload['image'] = $references[0]->dataUrl();
        }

        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders($this->headers())
            ->post($this->baseUrl().'/v1/images/generations', $payload);

        if ($response->status() === 400 && str_contains($response->body(), 'content_policy_violation')) {
            throw new ImageContentRejectedException("Flow gateway rejected the prompt: {$response->json('error.message')}");
        }

        if ($response->failed()) {
            throw new RuntimeException("Flow gateway image error ({$response->status()}): {$response->body()}");
        }

        $base64 = $response->json('data.0.b64_json');

        if (! is_string($base64) || $base64 === '') {
            throw new RuntimeException('Flow gateway returned no image data.');
        }

        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Flow gateway returned invalid image data.');
        }

        // Browser-session generations have no API cost.
        $this->usage->record(new UsageEvent(
            kind: 'image',
            provider: 'flow',
            model: $model,
            promptTokens: 0,
            outputTokens: 0,
            totalTokens: 0,
            costUsd: 0.0,
            estimated: false,
        ));

        return $bytes;
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        throw new RuntimeException('The flow-image gateway generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('cubfable.ai.flow_base_url'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $key = (string) config('cubfable.ai.keys.flow');

        return $key === '' ? [] : ['Authorization' => "Bearer {$key}"];
    }
}
