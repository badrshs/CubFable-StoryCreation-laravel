<?php

namespace App\Services\AI;

use App\Exceptions\ImageContentRejectedException;
use App\Exceptions\ImageFlaggedSensitiveException;
use App\Models\ImagePrompt;
use App\Services\BookStopSignal;
use App\Services\Prompts\SafetyPrompts;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Generate an image with content-flag recovery that changes the ENGINE
 * before it ever changes the prompt:
 *
 *   Round 1: the ORIGINAL prompt on the active engine, then on every engine
 *            of the configured fallback chain.
 *   Round 2: only when the whole chain refused on content grounds, the
 *            prompt is rewritten once (one text call) and the same engine
 *            order runs again.
 *
 * After both rounds the image is flagged for admin review
 * (ImageFlaggedSensitiveException) - never retried further.
 *
 * Transient errors (429/5xx/timeouts) retry the same engine once and never
 * trigger a rewrite; config/auth errors fail fast on the active engine and
 * simply skip a dead fallback engine. Content-refused attempts are not
 * billed by Replicate official models or Gemini, so the only added cost of
 * a full walk is the single text rewrite.
 */
class SafeImageGenerator
{
    /**
     * Tries per engine slot when the failure is transient (not content).
     */
    private const TRANSIENT_TRIES = 2;

    public function __construct(
        private AiManager $ai,
        private PromptSanitizer $sanitizer,
        private SafetyPrompts $prompts,
        private FallbackEngines $fallbacks,
        private BookStopSignal $stopSignal,
    ) {}

    /**
     * @param  list<ImageReference>  $references
     * @param  (callable(): array{prompt: string, references: list<ImageReference>})|null  $compose
     *                                                                                               Recomposes prompt and references for the CURRENTLY configured
     *                                                                                               engine (reference budgets differ per engine). When null, the
     *                                                                                               given prompt and references are used for every engine.
     */
    public function generate(string $prompt, string $size, array $references = [], string $label = 'image', ?PromptLogContext $log = null, ?callable $compose = null, bool $engineFallback = true): GeneratedImage
    {
        $engines = $engineFallback ? [null, ...$this->fallbacks->chain()] : [null];

        $attempt = 0;
        $lastException = null;
        $sawContentRejection = false;
        $rewrites = [];
        $aiRewriteBudget = 1;

        foreach ([1, 2] as $round) {
            // Round 2 exists only for content flags: rewriting the prompt
            // cannot fix an outage or a bad key.
            if ($round === 2 && ! $sawContentRejection) {
                break;
            }

            foreach ($engines as $engine) {
                $result = $this->withEngine($engine, function () use ($prompt, $size, $references, $label, $log, $compose, $round, $engine, &$attempt, &$lastException, &$sawContentRejection, &$rewrites, &$aiRewriteBudget): ?GeneratedImage {
                    $composed = $compose !== null ? $compose() : ['prompt' => $prompt, 'references' => $references];
                    $effectivePrompt = (string) $composed['prompt'];
                    $refs = $composed['references'];

                    if ($round === 2) {
                        $effectivePrompt = $this->safeRewrite($effectivePrompt, $rewrites, $aiRewriteBudget);
                    }

                    $variant = $round === 1 ? 'original' : 'safe-rewrite';

                    for ($try = 1; $try <= self::TRANSIENT_TRIES; $try++) {
                        // Without this, one image's full fallback walk (two
                        // rounds x every engine x transient retries) grinds on
                        // long after the admin pressed Stop; checking per
                        // attempt makes a stop land within one attempt.
                        $this->abortIfBookStopped($log);

                        $attempt++;
                        $journal = $this->journalAttempt($log, $attempt, $round, $variant, $effectivePrompt, $refs);

                        try {
                            $image = new GeneratedImage(
                                bytes: $this->ai->generateImage($effectivePrompt, $size, $refs),
                                prompt: $effectivePrompt,
                                attempt: $attempt,
                            );

                            $this->markAccepted($journal);

                            return $image;
                        } catch (Throwable $exception) {
                            $lastException = $exception;
                            $this->recordFailure($journal, $exception);

                            $reason = mb_substr($exception->getMessage(), 0, 160);
                            Log::warning(sprintf('[ai] %s: round %d attempt %d (%s, %s) failed: %s', $label, $round, $attempt, $variant, $this->currentEngineLabel(), $reason));

                            if ($this->isContentRejection($exception)) {
                                $sawContentRejection = true;

                                return null; // Next engine takes over; the prompt stays untouched.
                            }

                            if ($this->isNonRetryable($exception)) {
                                if ($engine === null) {
                                    throw $exception; // The active engine is misconfigured: nothing downstream can save this.
                                }

                                return null; // A dead fallback engine is simply skipped.
                            }

                            // Transient: same engine, same prompt, one more try.
                        }
                    }

                    return null;
                });

                if ($result !== null) {
                    return $result;
                }
            }
        }

        if ($sawContentRejection) {
            throw new ImageFlaggedSensitiveException(
                'Every engine refused this image on content grounds: '.mb_substr($lastException?->getMessage() ?? '', 0, 300),
                previous: $lastException,
            );
        }

        throw $lastException;
    }

    /**
     * Halt when the admin pressed Stop for the book this image belongs to.
     * Same message the pipeline's own checkpoints use, so the run aborts
     * identically wherever the stop lands.
     */
    private function abortIfBookStopped(?PromptLogContext $log): void
    {
        if ($log !== null && $this->stopSignal->requested($log->bookId)) {
            throw new RuntimeException('Generation stopped by the admin.');
        }
    }

    /**
     * Run the callback with a fallback engine applied to the config (the
     * same mechanism the dedicated cover engine uses), restoring the active
     * engine afterwards. A null engine means the active one.
     *
     * @param  array{provider: string, model: string}|null  $engine
     */
    private function withEngine(?array $engine, callable $callback): ?GeneratedImage
    {
        if ($engine === null) {
            return $callback();
        }

        $modelPath = "cubfable.ai.models.image.{$engine['provider']}";
        $originalProvider = config('cubfable.ai.image_provider');
        $originalModel = config($modelPath);

        config()->set('cubfable.ai.image_provider', $engine['provider']);
        config()->set($modelPath, $engine['model']);

        try {
            return $callback();
        } finally {
            config()->set('cubfable.ai.image_provider', $originalProvider);
            config()->set($modelPath, $originalModel);
        }
    }

    private function currentEngineLabel(): string
    {
        $provider = (string) config('cubfable.ai.image_provider');

        return $provider.':'.(string) config("cubfable.ai.models.image.{$provider}");
    }

    /**
     * The round-2 prompt for a composed round-1 prompt. At most one AI
     * rewrite call per generate() run; further distinct prompts (engines
     * whose composed prompt differs) fall back to the deterministic scrub.
     *
     * @param  array<string, string>  $cache
     */
    private function safeRewrite(string $prompt, array &$cache, int &$aiRewriteBudget): string
    {
        $key = md5($prompt);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        if ($aiRewriteBudget > 0) {
            $aiRewriteBudget--;

            return $cache[$key] = $this->rephraseForSafety($prompt);
        }

        return $cache[$key] = $this->sanitizer->sanitize($prompt);
    }

    /**
     * Journal one attempt to image_prompts. Never lets a bookkeeping failure
     * break generation.
     */
    /**
     * @param  list<ImageReference>  $references
     */
    private function journalAttempt(?PromptLogContext $log, int $attempt, int $round, string $variant, string $prompt, array $references = []): ?ImagePrompt
    {
        if ($log === null) {
            return null;
        }

        $provider = (string) config('cubfable.ai.image_provider');

        try {
            return ImagePrompt::query()->create([
                'book_id' => $log->bookId,
                'page_id' => $log->pageId,
                'purpose' => $log->purpose,
                'attempt' => $attempt,
                'round' => $round,
                'variant' => $variant,
                'provider' => $provider,
                'model' => (string) config("cubfable.ai.models.image.{$provider}"),
                'prompt' => $prompt,
                'references' => array_map(
                    fn (ImageReference $reference): array => ['path' => $reference->path, 'label' => $reference->label],
                    $references,
                ),
                'accepted' => false,
            ]);
        } catch (Throwable $exception) {
            Log::warning("Failed to journal an image prompt: {$exception->getMessage()}");

            return null;
        }
    }

    private function markAccepted(?ImagePrompt $journal): void
    {
        if ($journal === null) {
            return;
        }

        try {
            $journal->update(['accepted' => true]);
        } catch (Throwable $exception) {
            Log::warning("Failed to mark an image prompt accepted: {$exception->getMessage()}");
        }
    }

    private function recordFailure(?ImagePrompt $journal, Throwable $exception): void
    {
        if ($journal === null) {
            return;
        }

        try {
            $journal->update(['error' => mb_substr($exception->getMessage(), 0, 1000)]);
        } catch (Throwable $bookkeeping) {
            Log::warning("Failed to record an image prompt error: {$bookkeeping->getMessage()}");
        }
    }

    /**
     * Whether the engine refused on content-safety grounds (as opposed to
     * being down, throttled, or misconfigured).
     */
    private function isContentRejection(Throwable $exception): bool
    {
        if ($exception instanceof ImageContentRejectedException) {
            return true;
        }

        return preg_match('/nsfw|sensitive|moderat|content[_ ]?policy|flagged|unsafe|safety/i', $exception->getMessage()) === 1;
    }

    /**
     * Config/auth/network failures won't be fixed by retrying the same call.
     */
    private function isNonRetryable(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return preg_match('/api key|not set|unauthorized|\b401\b|\b403\b|invalid api|enotfound|fetch failed|econnrefused/', $message) === 1;
    }

    /**
     * Ask the configured text model to rewrite a blocked image prompt so it
     * passes safety filters while keeping every visual detail. Falls back to
     * the deterministic scrub if the text call fails or returns nothing.
     */
    private function rephraseForSafety(string $prompt): string
    {
        $instruction = $this->prompts->rephraseInstruction($prompt);

        try {
            $rewritten = trim($this->ai->generateText($instruction));

            return $rewritten !== '' ? $rewritten : $this->sanitizer->sanitize($prompt);
        } catch (Throwable) {
            return $this->sanitizer->sanitize($prompt);
        }
    }
}
