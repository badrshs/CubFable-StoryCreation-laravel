<?php

namespace App\Services\AI;

use App\Models\AiUsage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buffers the AI usage/cost events recorded by providers during a generation
 * run. Registered as a scoped singleton so it resets between queue jobs.
 */
class UsageCollector
{
    /**
     * Rough USD-per-1M-token rates [input, output]. Image-output tokens are
     * billed at a high rate (one image is roughly a fixed token block, e.g.
     * ~1290 tokens for gemini-2.5-flash-image -> ~$0.04). These are estimates;
     * raw token counts are stored too, so exact costs can be recomputed later
     * if rates change.
     *
     * @var array<string, array{in: float, out: float}>
     */
    private const RATES = [
        'gemini-2.5-flash' => ['in' => 0.3, 'out' => 2.5],
        'gemini-2.5-flash-lite' => ['in' => 0.1, 'out' => 0.4],
        'gemini-2.5-flash-image' => ['in' => 0.3, 'out' => 30.0],
        'gemini-3-flash-preview' => ['in' => 0.3, 'out' => 2.5],
    ];

    /** @var list<UsageEvent> */
    private array $events = [];

    public function record(UsageEvent $event): void
    {
        $this->events[] = $event;
    }

    public function estimateCost(string $model, int $promptTokens, int $outputTokens): ?float
    {
        $rate = self::RATES[$model] ?? null;

        if ($rate === null) {
            return null;
        }

        return ($promptTokens * $rate['in'] + $outputTokens * $rate['out']) / 1_000_000;
    }

    /**
     * Persist the buffered events to the ai_usage table. Never throws.
     */
    public function flush(?int $bookId): void
    {
        $events = $this->events;
        $this->events = [];

        if ($events === []) {
            return;
        }

        try {
            foreach ($events as $event) {
                AiUsage::query()->create([
                    'book_id' => $bookId,
                    'kind' => $event->kind,
                    'provider' => $event->provider,
                    'model' => $event->model,
                    'prompt_tokens' => $event->promptTokens,
                    'output_tokens' => $event->outputTokens,
                    'total_tokens' => $event->totalTokens,
                    'cost_usd' => $event->costUsd,
                    'estimated' => $event->estimated,
                ]);
            }

            $total = 0.0;

            foreach ($events as $event) {
                $total += $event->costUsd ?? 0.0;
            }

            Log::info(sprintf('[ai] book %s: %d AI call(s), est. cost $%s', $bookId ?? '?', count($events), number_format($total, 4, '.', '')));
        } catch (Throwable $exception) {
            Log::warning('[ai] failed to persist usage: '.$exception->getMessage());
        }
    }
}
