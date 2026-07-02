<?php

namespace App\Services\AI;

/**
 * A single AI call's usage/cost record, buffered by the UsageCollector until
 * it is flushed to the ai_usage table.
 */
final readonly class UsageEvent
{
    public function __construct(
        public string $kind,
        public string $provider,
        public string $model,
        public int $promptTokens,
        public int $outputTokens,
        public int $totalTokens,
        /** Actual cost when the provider reports it, else an estimate, else null. */
        public ?float $costUsd,
        /** True when costUsd is an estimate (token-rate), false when provider-reported. */
        public bool $estimated,
    ) {}
}
