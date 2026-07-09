<?php

namespace App\Services\Pdf;

/**
 * The printable trim sizes a storybook PDF can be composed at. Each preset
 * carries the trim in millimetres; the print variant adds its 3mm bleed on
 * top exactly as before. The admin picks one per install (runtime setting
 * pdf_page_size); square-210 reproduces the original hardcoded layout.
 */
class PageSize
{
    private const float MM = 2.834645669;

    /**
     * @var array<string, array{label: string, mmW: float, mmH: float}>
     */
    private const PRESETS = [
        'square-210' => ['label' => 'Square 210 x 210 mm (current default)', 'mmW' => 210, 'mmH' => 210],
        'square-216' => ['label' => 'Square 8.5 x 8.5 in (216 x 216 mm, POD standard)', 'mmW' => 215.9, 'mmH' => 215.9],
        'a4-portrait' => ['label' => 'A4 portrait (210 x 297 mm)', 'mmW' => 210, 'mmH' => 297],
        'a4-landscape' => ['label' => 'A4 landscape (297 x 210 mm)', 'mmW' => 297, 'mmH' => 210],
        'portrait-8x10' => ['label' => 'Portrait 8 x 10 in (203 x 254 mm)', 'mmW' => 203.2, 'mmH' => 254],
        'landscape-10x8' => ['label' => 'Landscape 10 x 8 in (254 x 203 mm)', 'mmW' => 254, 'mmH' => 203.2],
        'letter-portrait' => ['label' => 'US Letter portrait (216 x 279 mm)', 'mmW' => 215.9, 'mmH' => 279.4],
        'letter-landscape' => ['label' => 'US Letter landscape (279 x 216 mm)', 'mmW' => 279.4, 'mmH' => 215.9],
    ];

    public const string DEFAULT = 'square-210';

    private function __construct(
        public readonly string $key,
        public readonly float $trimW,
        public readonly float $trimH,
    ) {}

    /**
     * Resolve a preset key to trim dimensions in points. Unknown keys fall
     * back to the default so a stale stored setting can never break builds.
     */
    public static function fromKey(?string $key): self
    {
        $key = $key !== null && isset(self::PRESETS[$key]) ? $key : self::DEFAULT;
        $preset = self::PRESETS[$key];

        return new self($key, $preset['mmW'] * self::MM, $preset['mmH'] * self::MM);
    }

    /**
     * The preset list for the admin select.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::PRESETS as $key => $preset) {
            $options[] = ['key' => $key, 'label' => $preset['label']];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::PRESETS);
    }
}
