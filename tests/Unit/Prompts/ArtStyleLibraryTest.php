<?php

namespace Tests\Unit\Prompts;

use App\Enums\ArtStyle;
use App\Services\Prompts\ArtStyleLibrary;
use PHPUnit\Framework\TestCase;

class ArtStyleLibraryTest extends TestCase
{
    private ArtStyleLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = new ArtStyleLibrary;
    }

    public function test_every_selectable_style_has_a_complete_record(): void
    {
        foreach (ArtStyle::cases() as $style) {
            $key = $style->value;

            $this->assertNotSame('', $this->library->descriptor($key), "descriptor missing for {$key}");
            $this->assertNotEmpty($this->library->antiDriftHints($key), "anti-drift hints missing for {$key}");
            $this->assertNotSame('', $this->library->reinforcementLine($key), "reinforcement missing for {$key}");
            $this->assertNotSame('', $this->library->referenceAdaptationLine($key), "adaptation missing for {$key}");
        }
    }

    public function test_selectable_descriptors_are_unique(): void
    {
        $descriptors = array_map(
            fn (ArtStyle $style): string => $this->library->descriptor($style->value),
            ArtStyle::cases(),
        );

        $this->assertSame(count($descriptors), count(array_unique($descriptors)));
    }

    public function test_legacy_styles_resolve_with_generic_anti_drift(): void
    {
        foreach (['gouache', 'sticker-art', 'collage', 'block-world', 'geometric', 'pencil-sketch', 'digital-art'] as $key) {
            $this->assertNotSame('', $this->library->descriptor($key), "descriptor missing for legacy {$key}");
            $this->assertSame([], $this->library->antiDriftHints($key));
            $this->assertNotSame('', $this->library->reinforcementLine($key));
            $this->assertNotSame('', $this->library->referenceAdaptationLine($key));
        }
    }

    public function test_unknown_styles_fall_back_to_storybook(): void
    {
        $this->assertSame($this->library->descriptor('storybook'), $this->library->descriptor('does-not-exist'));
        $this->assertSame($this->library->antiDriftHints('storybook'), $this->library->antiDriftHints('does-not-exist'));
        $this->assertSame($this->library->reinforcementLine('storybook'), $this->library->reinforcementLine('does-not-exist'));
        $this->assertSame($this->library->referenceAdaptationLine('storybook'), $this->library->referenceAdaptationLine('does-not-exist'));
    }

    public function test_flat_styles_forbid_3d_and_3d_forbids_flat(): void
    {
        $cartoonHints = implode(' ', $this->library->antiDriftHints('cartoon'));
        $this->assertStringContainsString('no 3D rendering', $cartoonHints);

        $threeDHints = implode(' ', $this->library->antiDriftHints('3d-animation'));
        $this->assertStringContainsString('not flat 2D', $threeDHints);
    }

    public function test_every_adaptation_line_forbids_photorealism_and_keeps_likeness(): void
    {
        foreach ([...array_column(ArtStyle::cases(), 'value'), 'gouache'] as $key) {
            $line = $this->library->referenceAdaptationLine($key);

            $this->assertStringContainsString('never photographic', $line);
            // Identity framing, not preservation: no "keep"/"match" verbs
            // that invite copying the photo wholesale.
            $this->assertStringContainsString('only show who each character is', $line);
            $this->assertStringNotContainsString('keep', $line);
            // Clothing fits the SCENE, never the photo: a parent photographed
            // in a suit must not wear it while playing carpenter on a farm.
            $this->assertStringContainsString('dressed to fit the scene, never in the clothes from the photo', $line);
        }
    }
}
