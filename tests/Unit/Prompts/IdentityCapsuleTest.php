<?php

namespace Tests\Unit\Prompts;

use App\Models\Character;
use App\Services\Prompts\IdentityCapsule;
use PHPUnit\Framework\TestCase;

class IdentityCapsuleTest extends TestCase
{
    private IdentityCapsule $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identity = new IdentityCapsule;
    }

    public function test_reference_line_points_at_the_image_and_nothing_else(): void
    {
        // The photo IS the identity: no textual hair/outfit that could fight
        // it or lock in whatever the person happened to wear that day.
        $this->assertSame(
            'Mia: reference image 1. Expression: amazed.',
            $this->identity->referenceLine('Mia', 1, 'amazed'),
        );

        $this->assertSame(
            'Omar: reference image 2.',
            $this->identity->referenceLine('Omar', 2),
        );
    }

    public function test_description_line_carries_the_full_appearance_or_a_fallback(): void
    {
        $this->assertSame(
            'Mia: Short curly brown hair, green eyes. Expression: joyful.',
            $this->identity->descriptionLine('Mia', 'Short curly brown hair, green eyes.', 'joyful'),
        );

        $this->assertSame(
            'Omar: a friendly storybook character.',
            $this->identity->descriptionLine('Omar', null),
        );
    }

    public function test_adult_characters_are_marked_but_children_add_nothing(): void
    {
        // A companion like a mom or dad must not be drawn kid-sized; a child
        // marker would add nothing, and minor-age words in image prompts trip
        // provider safety filters.
        $this->assertSame(
            'Grandpa Joe (adult): reference image 2.',
            $this->identity->referenceLine('Grandpa Joe', 2, null, 'adult'),
        );

        $this->assertSame(
            'Mama (adult): Long black hair, a green cardigan.',
            $this->identity->descriptionLine('Mama', 'Long black hair, a green cardigan.', null, 'adult'),
        );

        $this->assertSame(
            'Mia: reference image 1.',
            $this->identity->referenceLine('Mia', 1, null, 'child'),
        );

        $this->assertSame(
            'Omar: a friendly storybook character.',
            $this->identity->descriptionLine('Omar', null, null, 'child'),
        );
    }

    public function test_invented_appearances_for_adults_demand_an_adult_build(): void
    {
        $adult = new Character([
            'name' => 'Grandpa Joe',
            'role' => 'grandfather',
            'age_group' => 'adult',
        ]);

        $prompt = $this->identity->textDescriptionPrompt($adult, 'watercolor');

        $this->assertStringContainsString('grown adult', $prompt);

        $kid = new Character(['name' => 'Mia', 'age_group' => 'child']);

        $this->assertStringNotContainsString('grown adult', $this->identity->textDescriptionPrompt($kid, 'watercolor'));
    }

    public function test_both_description_prompts_share_the_labeled_shape_and_avoid_age_and_formal_wear(): void
    {
        $photo = $this->identity->photoDescriptionInstruction();

        foreach (['HAIR:', 'EYES & FACE:', 'OUTFIT:', 'DISTINCTIVE FEATURES:'] as $label) {
            $this->assertStringContainsString($label, $photo);
        }

        $this->assertStringContainsString('Do NOT mention or estimate age', $photo);
        // A formal photo must not lock a suit into every illustration.
        $this->assertStringContainsString('no suits, ties, or uniforms', $photo);
    }
}
