<?php

namespace App\Services\AI\Replicate;

/**
 * One Replicate image model's verified capabilities: which field carries the
 * reference images (and its shape), the aspect ratios and resolution tiers the
 * model actually accepts, what each image costs, and whether it can render a
 * coherent multi-image set. Values are hand-verified against the model's live
 * input schema so the provider never guesses a parameter name.
 */
final readonly class ReplicateModel
{
    /**
     * @param  ?string  $referenceField  Field carrying reference images ('image_input', 'input_image', 'image_prompt'), or null when the model takes none.
     * @param  list<string>  $aspectRatios  Numeric ratios the model accepts; never 'custom' or 'match_input_image'.
     * @param  ?string  $sizeField  The resolution field's name ('size' or 'resolution'), or null when the model has none.
     * @param  list<string>  $sizeTiers  Allowed resolution tiers, ordered smallest to largest.
     * @param  non-empty-array<string, float>  $costPerImage  USD per output image, keyed by tier; key '' when the price is flat.
     * @param  array<string, mixed>  $staticParams  Extra input fields always sent verbatim.
     */
    public function __construct(
        public string $slug,
        public string $label,
        public string $description,
        public ?string $referenceField,
        public bool $referencesAreArray,
        public int $maxReferences,
        public array $aspectRatios,
        public ?string $sizeField,
        public array $sizeTiers,
        public ?string $outputFormat,
        public array $costPerImage,
        public bool $supportsGroups,
        public array $staticParams = [],
    ) {}

    /**
     * The resolution tier to request for a quality preference: standard is the
     * smallest tier, max the largest, and high the ~2K sweet spot. Null when
     * the model exposes no resolution field.
     */
    public function tierFor(string $quality): ?string
    {
        if ($this->sizeTiers === []) {
            return null;
        }

        return match (strtolower(trim($quality))) {
            'standard' => $this->sizeTiers[0],
            'max' => $this->sizeTiers[count($this->sizeTiers) - 1],
            default => in_array('2K', $this->sizeTiers, true)
                ? '2K'
                : $this->sizeTiers[min(1, count($this->sizeTiers) - 1)],
        };
    }

    /**
     * USD cost of one output image at the given tier (null for models without
     * tiers). Unknown tiers fall back to the flat rate, then the largest rate.
     */
    public function costFor(?string $tier): float
    {
        return $this->costPerImage[$tier ?? '']
            ?? $this->costPerImage['']
            ?? (float) max($this->costPerImage);
    }

    /**
     * A compact display string for what one image costs, e.g. "$0.04" or
     * "$0.045-$0.09" - for narrow dropdowns.
     */
    public function costLabel(): string
    {
        $min = min($this->costPerImage);
        $max = max($this->costPerImage);

        return $min === $max
            ? '$'.$this->formatCost($min)
            : '$'.$this->formatCost($min).'-$'.$this->formatCost($max);
    }

    /**
     * The full per-tier price breakdown, e.g. "1K $0.045 / 2K $0.09 per
     * image" or "$0.04 per image" when the price is flat.
     */
    public function costDetail(): string
    {
        $parts = [];

        foreach ($this->costPerImage as $tier => $cost) {
            $parts[] = ($tier === '' ? '' : $tier.' ').'$'.$this->formatCost($cost);
        }

        return implode(' / ', $parts).' per image';
    }

    private function formatCost(float $cost): string
    {
        $formatted = number_format($cost, 3);

        // Keep at least two decimals: "0.300" reads as "0.30", not "0.3".
        return str_ends_with($formatted, '0') ? substr($formatted, 0, -1) : $formatted;
    }
}
