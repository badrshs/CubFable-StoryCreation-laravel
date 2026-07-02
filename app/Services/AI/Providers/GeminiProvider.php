<?php

namespace App\Services\AI\Providers;

use App\Exceptions\ImageContentRejectedException;
use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use App\Services\AI\UsageEvent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider implements AiProvider
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    /**
     * Gemini finishReason values that mean the model declined on content grounds.
     *
     * @var list<string>
     */
    private const REFUSAL_FINISH_REASONS = [
        'IMAGE_OTHER',
        'SAFETY',
        'IMAGE_SAFETY',
        'PROHIBITED_CONTENT',
        'IMAGE_PROHIBITED_CONTENT',
        'RECITATION',
        'BLOCKLIST',
        'SPII',
    ];

    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        $model = (string) config('cubfable.ai.models.text.gemini');

        $response = Http::timeout(180)->post(self::BASE."/{$model}:generateContent?key={$this->apiKey()}", [
            'contents' => [['parts' => [['text' => $prompt]]]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini text error ({$response->status()}): {$response->body()}");
        }

        $this->recordGemini('text', $model, $response->json('usageMetadata'));

        return $this->joinTextParts($response->json('candidates.0.content.parts'));
    }

    /**
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.gemini');

        // Imagen models use the :predict endpoint and are prompt-only (no reference).
        if (preg_match('/^imagen/i', $model) === 1) {
            return $this->imagenPredict($model, $prompt);
        }

        // Gemini image models (e.g. gemini-2.5-flash-image) use :generateContent
        // and can take one or more reference photos as inline image parts.
        $parts = [['text' => $prompt]];

        foreach ($references as $reference) {
            $parsed = $this->parseDataUrl($reference->dataUrl());

            if ($parsed !== null) {
                $parts[] = ['inlineData' => ['mimeType' => $parsed['mimeType'], 'data' => $parsed['base64']]];
            }
        }

        $response = Http::timeout(180)->post(self::BASE."/{$model}:generateContent?key={$this->apiKey()}", [
            'contents' => [['parts' => $parts]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini image error ({$response->status()}): {$response->body()}");
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $candidate = $data['candidates'][0] ?? null;
        $candidate = is_array($candidate) ? $candidate : [];
        $outParts = $candidate['content']['parts'] ?? [];
        $base64 = null;

        if (is_array($outParts)) {
            foreach ($outParts as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $inline = $part['inlineData']['data'] ?? $part['inline_data']['data'] ?? null;

                if (is_string($inline) && $inline !== '') {
                    $base64 = $inline;
                    break;
                }
            }
        }

        if ($base64 === null) {
            $finishReason = (string) ($candidate['finishReason'] ?? '');
            $finishMessage = $candidate['finishMessage'] ?? null;
            $detail = is_string($finishMessage) && $finishMessage !== ''
                ? $finishMessage
                : ($finishReason !== '' ? $finishReason : 'no image returned');

            if (in_array($finishReason, self::REFUSAL_FINISH_REASONS, true)) {
                throw new ImageContentRejectedException("Gemini declined to generate image ({$finishReason}): {$detail}");
            }

            throw new RuntimeException('Gemini returned no image. Response: '.substr((string) json_encode($data), 0, 400));
        }

        $this->recordGemini('image', $model, $data['usageMetadata'] ?? null);

        return base64_decode($base64);
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        $model = (string) config('cubfable.ai.models.text.gemini');
        $parsed = $this->parseDataUrl($photoDataUrl);
        $parts = [['text' => $instruction]];

        if ($parsed !== null) {
            $parts[] = ['inlineData' => ['mimeType' => $parsed['mimeType'], 'data' => $parsed['base64']]];
        }

        $response = Http::timeout(180)->post(self::BASE."/{$model}:generateContent?key={$this->apiKey()}", [
            'contents' => [['parts' => $parts]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini vision error ({$response->status()}): {$response->body()}");
        }

        $this->recordGemini('vision', $model, $response->json('usageMetadata'));

        return $this->joinTextParts($response->json('candidates.0.content.parts'));
    }

    private function imagenPredict(string $model, string $prompt): string
    {
        $response = Http::timeout(180)->post(self::BASE."/{$model}:predict?key={$this->apiKey()}", [
            'instances' => [['prompt' => $prompt]],
            'parameters' => ['sampleCount' => 1],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Imagen error ({$response->status()}): {$response->body()}");
        }

        $base64 = $response->json('predictions.0.bytesBase64Encoded');

        if (! is_string($base64) || $base64 === '') {
            throw new RuntimeException('Imagen returned no image data.');
        }

        $this->usage->record(new UsageEvent(
            kind: 'image',
            provider: 'gemini',
            model: $model,
            promptTokens: 0,
            outputTokens: 0,
            totalTokens: 0,
            costUsd: 0.04, // Imagen bills a flat per-image rate
            estimated: true,
        ));

        return base64_decode($base64);
    }

    private function apiKey(): string
    {
        $key = (string) config('cubfable.ai.keys.gemini');

        if ($key === '') {
            throw new RuntimeException('GEMINI_API_KEY is not set.');
        }

        return $key;
    }

    private function recordGemini(string $kind, string $model, mixed $usageMetadata): void
    {
        $usageMetadata = is_array($usageMetadata) ? $usageMetadata : [];
        $promptTokens = (int) ($usageMetadata['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($usageMetadata['candidatesTokenCount'] ?? 0);

        $this->usage->record(new UsageEvent(
            kind: $kind,
            provider: 'gemini',
            model: $model,
            promptTokens: $promptTokens,
            outputTokens: $outputTokens,
            totalTokens: (int) ($usageMetadata['totalTokenCount'] ?? $promptTokens + $outputTokens),
            costUsd: $this->usage->estimateCost($model, $promptTokens, $outputTokens),
            estimated: true,
        ));
    }

    /**
     * Parse a `data:image/...;base64,...` URL into its mime type and base64 body.
     *
     * @return array{mimeType: string, base64: string}|null
     */
    private function parseDataUrl(string $dataUrl): ?array
    {
        if (preg_match('/^data:(image\/[a-z0-9.+-]+);base64,(.+)$/i', $dataUrl, $matches) !== 1) {
            return null;
        }

        return ['mimeType' => strtolower($matches[1]), 'base64' => $matches[2]];
    }

    private function joinTextParts(mixed $parts): string
    {
        if (! is_array($parts)) {
            return '';
        }

        $texts = [];

        foreach ($parts as $part) {
            $text = is_array($part) ? ($part['text'] ?? null) : null;

            if (is_string($text) && $text !== '') {
                $texts[] = $text;
            }
        }

        return implode('', $texts);
    }
}
