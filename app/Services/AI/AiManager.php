<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\AiProvider;
use App\Services\AI\Providers\FlowImageProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\AI\Providers\OpenRouterProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Dispatches AI calls to the configured provider. Text and image providers are
 * selected independently; vision (photo description) always follows the text
 * provider, since all three default text models are multimodal.
 */
class AiManager
{
    public function __construct(private Container $container) {}

    public function generateText(string $prompt, int $maxTokens = 2048): string
    {
        return $this->textProvider()->text($prompt, $maxTokens);
    }

    /**
     * @param  list<ImageReference>  $references
     */
    public function generateImage(string $prompt, string $size, array $references = []): string
    {
        return $this->imageProvider()->image($prompt, $size, $references);
    }

    public function describeImage(string $instruction, string $photoDataUrl): string
    {
        return $this->textProvider()->describe($instruction, $photoDataUrl);
    }

    private function textProvider(): AiProvider
    {
        return $this->resolve((string) config('cubfable.ai.text_provider'));
    }

    private function imageProvider(): AiProvider
    {
        return $this->resolve((string) config('cubfable.ai.image_provider'));
    }

    private function resolve(string $provider): AiProvider
    {
        return match (strtolower(trim($provider))) {
            'gemini' => $this->container->make(GeminiProvider::class),
            'openrouter' => $this->container->make(OpenRouterProvider::class),
            'flow' => $this->container->make(FlowImageProvider::class),
            default => $this->container->make(OpenAiProvider::class),
        };
    }
}
