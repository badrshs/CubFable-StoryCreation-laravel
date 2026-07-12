<?php

namespace Tests\Feature\Prompts;

use App\Services\Prompts\ReferencePolicy;
use Tests\TestCase;

class ReferencePolicyTest extends TestCase
{
    private ReferencePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new ReferencePolicy;
        config()->set('cubfable.ai.image_provider', 'replicate');
        config()->set('cubfable.ai.max_image_references', 0);
    }

    public function test_multi_image_replicate_models_carry_several_references(): void
    {
        foreach (['bytedance/seedream-5-pro', 'bytedance/seedream-5-lite', 'bytedance/seedream-4.5', 'google/nano-banana-2', 'google/nano-banana-pro', 'black-forest-labs/flux-2-pro', 'black-forest-labs/flux-2-max'] as $model) {
            config()->set('cubfable.ai.models.image.replicate', $model);

            $this->assertSame(6, $this->policy->budget(), "{$model} should read a multi-image array");
        }
    }

    public function test_the_admin_cap_constrains_multi_image_models(): void
    {
        config()->set('cubfable.ai.max_image_references', 3);
        config()->set('cubfable.ai.models.image.replicate', 'bytedance/seedream-5-pro');

        $this->assertSame(3, $this->policy->budget());
    }

    public function test_single_reference_models_take_one_source_image(): void
    {
        foreach (['black-forest-labs/flux-kontext-pro', 'black-forest-labs/flux-kontext-max', 'black-forest-labs/flux-1.1-pro'] as $model) {
            config()->set('cubfable.ai.models.image.replicate', $model);

            $this->assertSame(1, $this->policy->budget(), "{$model} should carry exactly one reference");
        }
    }

    public function test_engines_without_references_switch_identity_to_text(): void
    {
        foreach (['ideogram-ai/ideogram-v3-turbo', 'recraft-ai/recraft-v3'] as $model) {
            config()->set('cubfable.ai.models.image.replicate', $model);

            $this->assertSame(0, $this->policy->budget(), "{$model} takes no character references");
        }
    }

    public function test_openai_carries_a_multi_image_array_capped_at_six(): void
    {
        config()->set('cubfable.ai.image_provider', 'openai');

        $this->assertSame(6, $this->policy->budget());

        config()->set('cubfable.ai.max_image_references', 2);
        $this->assertSame(2, $this->policy->budget());
    }

    public function test_gemini_carries_references_but_imagen_models_take_none(): void
    {
        config()->set('cubfable.ai.image_provider', 'gemini');

        config()->set('cubfable.ai.models.image.gemini', 'gemini-2.5-flash-image');
        $this->assertSame(6, $this->policy->budget());

        config()->set('cubfable.ai.models.image.gemini', 'imagen-4.0-generate-001');
        $this->assertSame(0, $this->policy->budget(), 'Imagen predict is prompt-only: identity must switch to text');
    }

    public function test_unknown_slugs_fall_back_to_the_name_heuristic(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'someone/seedream-fork');
        $this->assertSame(6, $this->policy->budget());

        config()->set('cubfable.ai.models.image.replicate', 'someone/unknown-editor');
        $this->assertSame(1, $this->policy->budget());
    }
}
