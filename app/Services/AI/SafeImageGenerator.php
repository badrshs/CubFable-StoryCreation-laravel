<?php

namespace App\Services\AI;

use App\Models\ImagePrompt;
use App\Services\Prompts\SafetyPrompts;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generate an image, retrying with progressively "safer" prompts when the
 * provider refuses on content grounds. Up to 3 retries after the first attempt:
 *   1. original prompt
 *   2. deterministic scrub (free)
 *   3. AI-rephrased prompt (one text call)
 *   4. AI-rephrased prompt with the reference photo dropped (a real child's
 *      photo can itself trip the minor-safety filter)
 * Refused image attempts are not billed by Gemini, so the only added cost is
 * the single text rewrite.
 */
class SafeImageGenerator
{
    public function __construct(
        private AiManager $ai,
        private PromptSanitizer $sanitizer,
        private SafetyPrompts $prompts,
    ) {}

    /**
     * @param  list<ImageReference>  $references
     */
    public function generate(string $prompt, string $size, array $references = [], string $label = 'image', ?PromptLogContext $log = null): GeneratedImage
    {
        $rephrased = null;
        $getRephrased = function () use (&$rephrased, $prompt): string {
            return $rephrased ??= $this->rephraseForSafety($prompt);
        };

        $attempts = [
            ['desc' => 'original', 'getPrompt' => fn (): string => $prompt, 'refs' => $references],
            ['desc' => 'scrubbed', 'getPrompt' => fn (): string => $this->sanitizer->sanitize($prompt), 'refs' => $references],
            ['desc' => 'ai-rephrased', 'getPrompt' => $getRephrased, 'refs' => $references],
            ['desc' => 'ai-rephrased, no photos', 'getPrompt' => $getRephrased, 'refs' => []],
        ];

        $lastException = null;

        foreach ($attempts as $index => $attempt) {
            try {
                $effectivePrompt = ($attempt['getPrompt'])();
                $journal = $this->journalAttempt($log, $index + 1, $attempt['desc'], $effectivePrompt);

                $image = new GeneratedImage(
                    bytes: $this->ai->generateImage($effectivePrompt, $size, $attempt['refs']),
                    prompt: $effectivePrompt,
                    attempt: $index + 1,
                );

                $this->markAccepted($journal);

                return $image;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if ($this->isNonRetryable($exception)) {
                    throw $exception;
                }

                $reason = mb_substr($exception->getMessage(), 0, 160);
                Log::warning(sprintf('[ai] %s: attempt %d/%d (%s) failed: %s', $label, $index + 1, count($attempts), $attempt['desc'], $reason));
            }
        }

        throw $lastException;
    }

    /**
     * Journal one attempt to image_prompts. Never lets a bookkeeping failure
     * break generation.
     */
    private function journalAttempt(?PromptLogContext $log, int $attempt, string $variant, string $prompt): ?ImagePrompt
    {
        if ($log === null) {
            return null;
        }

        try {
            return ImagePrompt::query()->create([
                'book_id' => $log->bookId,
                'page_id' => $log->pageId,
                'purpose' => $log->purpose,
                'attempt' => $attempt,
                'variant' => $variant,
                'prompt' => $prompt,
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

    /**
     * Config/auth/network failures won't be fixed by rephrasing, so fail fast on them.
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
