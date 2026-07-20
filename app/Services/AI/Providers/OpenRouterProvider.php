<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use App\Services\AI\UsageEvent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenRouterProvider implements AiProvider
{
    private const URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * The story blueprint is one long completion, and slow reasoning models
     * (deepseek-v4-pro takes ~3 minutes) were brushing against the previous
     * 180s ceiling, failing runs intermittently with cURL timeouts.
     */
    private const TEXT_TIMEOUT_SECONDS = 600;

    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        $model = (string) config('cubfable.ai.models.text.openrouter');

        $response = Http::timeout(self::TEXT_TIMEOUT_SECONDS)->withHeaders($this->headers())->post(self::URL, [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'usage' => ['include' => true],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenRouter text error ({$response->status()}): {$response->body()}");
        }

        $this->recordOpenRouter('text', $model, $response->json('usage'));

        $content = $response->json('choices.0.message.content');

        if ($content === null) {
            throw new RuntimeException('OpenRouter returned an empty response.');
        }

        return $this->stringify($content);
    }

    /**
     * OpenRouter routes image models through chat/completions. Reference photos
     * (best effort - only multimodal image models honor them) go in as
     * image_url content parts.
     *
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.openrouter');

        $maxReferences = (int) config('cubfable.ai.max_image_references', 0);

        if ($maxReferences > 0 && count($references) > $maxReferences) {
            $references = array_slice($references, 0, $maxReferences);
        }

        $content = $references === []
            ? $prompt
            : [
                ['type' => 'text', 'text' => $prompt],
                ...array_map(
                    fn (ImageReference $reference): array => ['type' => 'image_url', 'image_url' => ['url' => $reference->dataUrl()]],
                    $references,
                ),
            ];

        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $content]],
            'modalities' => ['image', 'text'],
            'usage' => ['include' => true],
        ];

        $response = Http::timeout(180)->withHeaders($this->headers())->post(self::URL, $payload);

        // Pure image models (e.g. Grok Imagine) reject a text output modality;
        // interleaved models (e.g. Gemini) require it. Fall back automatically.
        if ($response->status() === 404 && str_contains($response->body(), 'output modalities')) {
            $payload['modalities'] = ['image'];
            $response = Http::timeout(180)->withHeaders($this->headers())->post(self::URL, $payload);
        }

        if ($response->failed()) {
            throw new RuntimeException("OpenRouter image error ({$response->status()}): {$response->body()}");
        }

        $data = $response->json();
        $this->recordOpenRouter('image', $model, is_array($data) ? ($data['usage'] ?? null) : null);

        return $this->extractImage(is_array($data) ? $data : []);
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        // Vision defaults to the text model but can be pinned separately, so a
        // text-only story model (e.g. DeepSeek) never breaks photo description.
        $model = (string) ((string) config('cubfable.ai.models.vision.openrouter') !== ''
            ? config('cubfable.ai.models.vision.openrouter')
            : config('cubfable.ai.models.text.openrouter'));

        $response = Http::timeout(180)->withHeaders($this->headers())->post(self::URL, [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $instruction],
                        ['type' => 'image_url', 'image_url' => ['url' => $photoDataUrl]],
                    ],
                ],
            ],
            'usage' => ['include' => true],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenRouter vision error ({$response->status()}): {$response->body()}");
        }

        $this->recordOpenRouter('vision', $model, $response->json('usage'));

        $content = $response->json('choices.0.message.content');

        return $content === null ? '' : $this->stringify($content);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $key = (string) config('cubfable.ai.keys.openrouter');

        if ($key === '') {
            throw new RuntimeException('OPENROUTER_API_KEY is not set.');
        }

        return [
            'Authorization' => "Bearer {$key}",
            'HTTP-Referer' => 'http://localhost:19732',
            'X-Title' => 'CubFable',
        ];
    }

    /**
     * OpenRouter returns an actual `usage.cost` (USD) when asked, so record
     * that directly rather than estimating.
     */
    private function recordOpenRouter(string $kind, string $model, mixed $usage): void
    {
        $usage = is_array($usage) ? $usage : [];
        $cost = is_int($usage['cost'] ?? null) || is_float($usage['cost'] ?? null) ? (float) $usage['cost'] : null;

        $this->usage->record(new UsageEvent(
            kind: $kind,
            provider: 'openrouter',
            model: $model,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? 0),
            costUsd: $cost,
            estimated: $cost === null,
        ));
    }

    /**
     * Models return images in several shapes; cover the common ones.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function extractImage(array $data): string
    {
        $message = $data['choices'][0]['message'] ?? [];
        $message = is_array($message) ? $message : [];

        $images = $message['images'] ?? null;

        if (is_array($images) && $images !== []) {
            $image = reset($images);
            $image = is_array($image) ? $image : [];

            $imageUrl = $image['image_url'] ?? null;
            $url = null;

            if (is_array($imageUrl) && is_string($imageUrl['url'] ?? null)) {
                $url = $imageUrl['url'];
            } elseif (is_string($imageUrl)) {
                $url = $imageUrl;
            } elseif (is_string($image['url'] ?? null)) {
                $url = $image['url'];
            }

            if (is_string($url) && $url !== '') {
                return $this->bytesFromUrlOrDataUrl($url);
            }

            if (is_string($image['b64_json'] ?? null) && $image['b64_json'] !== '') {
                return $this->decodeBase64($image['b64_json']);
            }

            if (is_string($image['data'] ?? null) && $image['data'] !== '') {
                return $this->decodeBase64($image['data']);
            }
        }

        $messageContent = $message['content'] ?? null;

        if (is_array($messageContent)) {
            foreach ($messageContent as $part) {
                if (! is_array($part) || ($part['type'] ?? null) !== 'image_url') {
                    continue;
                }

                $partUrl = $part['image_url'] ?? null;
                $url = is_array($partUrl) ? ($partUrl['url'] ?? null) : $partUrl;

                if (is_string($url) && $url !== '') {
                    return $this->bytesFromUrlOrDataUrl($url);
                }
            }
        }

        if (is_string($messageContent) && $messageContent !== '') {
            if (str_starts_with($messageContent, 'data:image/')) {
                return $this->bytesFromUrlOrDataUrl($messageContent);
            }

            if (preg_match('/!\[.*?\]\((https?:\/\/[^)]+)\)/', $messageContent, $markdown) === 1) {
                return $this->bytesFromUrlOrDataUrl($markdown[1]);
            }

            if (preg_match('/^https?:\/\//', trim($messageContent)) === 1) {
                return $this->bytesFromUrlOrDataUrl(trim($messageContent));
            }
        }

        throw new RuntimeException(
            'OpenRouter returned no image. Raw: '.substr((string) json_encode($messageContent ?? $message), 0, 400),
        );
    }

    /**
     * Fetch raw bytes from an http(s) URL or decode a base64 data URL.
     */
    private function bytesFromUrlOrDataUrl(string $value): string
    {
        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');

            return $this->decodeBase64($comma === false ? '' : substr($value, $comma + 1));
        }

        $response = Http::timeout(180)->get($value);

        if ($response->failed()) {
            throw new RuntimeException("Failed to download image ({$response->status()})");
        }

        return $response->body();
    }

    private function decodeBase64(string $base64): string
    {
        return base64_decode($base64);
    }

    private function stringify(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_scalar($content)) {
            return (string) $content;
        }

        return (string) json_encode($content);
    }
}
