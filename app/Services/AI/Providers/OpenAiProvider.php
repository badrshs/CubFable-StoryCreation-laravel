<?php

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use App\Services\AI\UsageEvent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProvider
{
    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        $model = (string) config('cubfable.ai.models.text.openai');

        $response = Http::timeout(180)
            ->withToken($this->apiKey())
            ->post($this->baseUrl().'/chat/completions', [
                'model' => $model,
                'max_completion_tokens' => $maxTokens,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ])
            ->throw();

        $this->recordChatUsage('text', $model, $response->json('usage'));

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : '';
    }

    /**
     * Generate an image via gpt-image-1. The edit/reference flow is used with
     * the first reference (the main character) when one is present.
     *
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.openai');

        $primary = $references[0] ?? null;
        $parts = $primary instanceof ImageReference ? $this->parseImageDataUrl($primary->dataUrl()) : null;

        if ($parts === null) {
            $response = Http::timeout(180)
                ->withToken($this->apiKey())
                ->post($this->baseUrl().'/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'size' => $size,
                ])
                ->throw();
        } else {
            $response = Http::timeout(180)
                ->withToken($this->apiKey())
                ->attach('image', $parts['bytes'], $parts['filename'], ['Content-Type' => $parts['type']])
                ->post($this->baseUrl().'/images/edits', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'size' => $size,
                ])
                ->throw();
        }

        // The images endpoints do not surface token usage; record the call with unknown cost.
        $this->usage->record(new UsageEvent(
            kind: 'image',
            provider: 'openai',
            model: $model,
            promptTokens: 0,
            outputTokens: 0,
            totalTokens: 0,
            costUsd: null,
            estimated: true,
        ));

        $base64 = $response->json('data.0.b64_json');

        return base64_decode(is_string($base64) ? $base64 : '');
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        $model = (string) config('cubfable.ai.models.text.openai');

        $response = Http::timeout(180)
            ->withToken($this->apiKey())
            ->post($this->baseUrl().'/chat/completions', [
                'model' => $model,
                'max_completion_tokens' => 400,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $instruction],
                            ['type' => 'image_url', 'image_url' => ['url' => $photoDataUrl]],
                        ],
                    ],
                ],
            ])
            ->throw();

        $this->recordChatUsage('vision', $model, $response->json('usage'));

        $content = $response->json('choices.0.message.content');

        return is_string($content) ? $content : '';
    }

    private function apiKey(): string
    {
        $key = (string) config('cubfable.ai.keys.openai');

        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY must be set.');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('cubfable.ai.openai_base_url'), '/');
    }

    private function recordChatUsage(string $kind, string $model, mixed $usage): void
    {
        $usage = is_array($usage) ? $usage : [];

        $this->usage->record(new UsageEvent(
            kind: $kind,
            provider: 'openai',
            model: $model,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            totalTokens: (int) ($usage['total_tokens'] ?? 0),
            costUsd: null,
            estimated: true,
        ));
    }

    /**
     * Parse a `data:image/...;base64,...` URL into the parts the OpenAI edit
     * endpoint needs. Returns null if it is not a usable base64 image data URL.
     *
     * @return array{bytes: string, type: string, filename: string}|null
     */
    private function parseImageDataUrl(string $dataUrl): ?array
    {
        if (preg_match('/^data:(image\/(png|jpe?g|webp));base64,(.+)$/i', $dataUrl, $matches) !== 1) {
            return null;
        }

        $type = strtolower($matches[1]);
        $extension = $type === 'image/jpeg' || $type === 'image/jpg' ? 'jpg' : explode('/', $type)[1];
        $bytes = base64_decode($matches[3], true);

        if ($bytes === false) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'type' => $type === 'image/jpg' ? 'image/jpeg' : $type,
            'filename' => 'reference.'.$extension,
        ];
    }
}
