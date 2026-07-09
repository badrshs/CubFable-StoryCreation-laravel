<?php

namespace App\Services\AI\Providers;

use App\Exceptions\ImageContentRejectedException;
use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\ImageReference;
use App\Services\AI\UsageCollector;
use App\Services\AI\UsageEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * PiAPI's Flux API (images only). With a reference image the request runs
 * the Kontext edit task (subject-preserving image-to-image); without one it
 * falls back to plain txt2img on the same model. PiAPI is asynchronous:
 * POST /api/v1/task returns a task id that is polled until it completes.
 */
class PiapiProvider implements AiProvider
{
    /**
     * Kontext edits take exactly one source image.
     */
    public const int MAX_REFERENCES = 1;

    private const int POLL_INTERVAL_SECONDS = 3;

    private const int POLL_TIMEOUT_SECONDS = 300;

    public function __construct(private UsageCollector $usage) {}

    public function text(string $prompt, int $maxTokens): string
    {
        throw new RuntimeException('The piapi image provider generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * @param  list<ImageReference>  $references
     */
    public function image(string $prompt, string $size, array $references): string
    {
        $model = (string) config('cubfable.ai.models.image.piapi');
        [$width, $height] = $this->dimensions($size);

        $input = [
            'prompt' => $prompt,
            'width' => $width,
            'height' => $height,
        ];

        $taskType = 'txt2img';

        if ($references !== []) {
            // Kontext keeps the referenced subject and repaints everything
            // else from the prompt; it takes exactly one source image, as a
            // URL or base64 data URL.
            $taskType = 'kontext';
            $input['image'] = $references[0]->dataUrl();
        }

        $task = $this->createTask($model, $taskType, $input);
        $output = $this->awaitTask($task);
        $bytes = $this->extractImage($output);

        $this->usage->record(new UsageEvent(
            kind: 'image',
            provider: 'piapi',
            model: $model,
            promptTokens: 0,
            outputTokens: 0,
            totalTokens: 0,
            costUsd: 0.02,
            estimated: true,
        ));

        return $bytes;
    }

    public function describe(string $instruction, string $photoDataUrl): string
    {
        throw new RuntimeException('The piapi image provider generates images only; set TEXT_PROVIDER to openai, gemini, or openrouter.');
    }

    /**
     * Submit the generation task and return its id.
     *
     * @param  array<string, mixed>  $input
     */
    private function createTask(string $model, string $taskType, array $input): string
    {
        $response = Http::timeout(60)
            ->withHeaders(['X-API-Key' => $this->apiKey()])
            ->post($this->baseUrl().'/api/v1/task', [
                'model' => $model,
                'task_type' => $taskType,
                'input' => $input,
            ]);

        if ($response->failed()) {
            $this->throwForFailure("PiAPI task creation failed ({$response->status()})", $response->body());
        }

        $taskId = (string) $response->json('data.task_id');

        if ($taskId === '') {
            throw new RuntimeException('PiAPI returned no task id: '.$response->body());
        }

        return $taskId;
    }

    /**
     * Poll the task until it completes and return the raw task data.
     *
     * @return array<string, mixed>
     */
    private function awaitTask(string $taskId): array
    {
        $deadline = time() + self::POLL_TIMEOUT_SECONDS;

        while (time() < $deadline) {
            $response = Http::timeout(60)
                ->withHeaders(['X-API-Key' => $this->apiKey()])
                ->get($this->baseUrl().'/api/v1/task/'.$taskId);

            if ($response->failed()) {
                throw new RuntimeException("PiAPI task poll failed ({$response->status()}): {$response->body()}");
            }

            $data = (array) $response->json('data');
            $status = strtolower((string) ($data['status'] ?? ''));

            if (in_array($status, ['completed', 'success', 'finished'], true)) {
                return $data;
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                $error = json_encode($data['error'] ?? ($data['task_result']['error_messages'] ?? 'unknown error'));
                $this->throwForFailure('PiAPI task failed', (string) $error);
            }

            Sleep::for(self::POLL_INTERVAL_SECONDS)->seconds();
        }

        throw new RuntimeException("PiAPI task {$taskId} did not complete within ".self::POLL_TIMEOUT_SECONDS.' seconds.');
    }

    /**
     * Pull the image bytes out of a completed task: an inline base64 payload
     * when present, else a download from the result URL. The output shape
     * varies between doc versions, so both locations are tried.
     *
     * @param  array<string, mixed>  $data
     */
    private function extractImage(array $data): string
    {
        $output = (array) ($data['output'] ?? ($data['task_result']['task_output'] ?? []));

        $base64 = (string) ($output['image_base64'] ?? '');

        if ($base64 !== '') {
            $bytes = base64_decode((string) preg_replace('/^data:image\/\w+;base64,/', '', $base64), true);

            if ($bytes !== false && $bytes !== '') {
                return $bytes;
            }
        }

        $url = (string) ($output['image_url'] ?? ($output['image_urls'][0] ?? ''));

        if (str_starts_with($url, 'http')) {
            $download = Http::timeout(120)->get($url);

            if ($download->successful() && $download->body() !== '') {
                return $download->body();
            }
        }

        throw new RuntimeException('PiAPI returned no usable image data: '.json_encode($output));
    }

    /**
     * Route content-safety refusals to the rephrase ladder; everything else
     * is a plain failure.
     */
    private function throwForFailure(string $context, string $detail): never
    {
        if (preg_match('/nsfw|moderat|content[_ ]policy|flagged|unsafe|prohibited/i', $detail) === 1) {
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

    private function baseUrl(): string
    {
        return rtrim((string) config('cubfable.ai.piapi_base_url'), '/');
    }

    private function apiKey(): string
    {
        $key = (string) config('cubfable.ai.keys.piapi');

        if ($key === '') {
            throw new RuntimeException('PIAPI_API_KEY is not set.');
        }

        return $key;
    }
}
