<?php

namespace App\Services\AI;

/**
 * Maps the admin-configured image aspect ratio to the WxH request size the
 * pipeline asks providers for. Ratio-preset engines (Replicate catalog
 * models) recover the exact ratio from these sizes; pixel engines get a
 * ~1.5-megapixel canvas in the right orientation.
 */
class ImageSizePolicy
{
    private const DEFAULT_RATIO = '3:4';

    /**
     * One canonical size per selectable ratio, long side 1536.
     *
     * @var array<string, string>
     */
    private const SIZES = [
        '3:4' => '1152x1536',
        '4:3' => '1536x1152',
        '9:16' => '864x1536',
        '16:9' => '1536x864',
        '2:3' => '1024x1536',
        '3:2' => '1536x1024',
        '1:1' => '1024x1024',
        '4:5' => '1229x1536',
        '5:4' => '1536x1229',
    ];

    /**
     * The size every page and cover is generated at, from the configured
     * aspect ratio.
     */
    public function bookSize(): string
    {
        $ratio = trim((string) config('cubfable.ai.image_aspect_ratio', self::DEFAULT_RATIO));

        return self::SIZES[$ratio] ?? self::SIZES[self::DEFAULT_RATIO];
    }

    /**
     * The character sheet's size: always portrait, regardless of the book
     * ratio - it is an identity reference, not book art.
     */
    public function sheetSize(): string
    {
        return '1024x1536';
    }

    /**
     * The ratios the admin can choose from, default first.
     *
     * @return list<string>
     */
    public static function selectableRatios(): array
    {
        return array_keys(self::SIZES);
    }
}
