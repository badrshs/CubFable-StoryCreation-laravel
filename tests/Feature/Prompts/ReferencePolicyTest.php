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
        config()->set('cubfable.ai.max_image_references', 3);
    }

    public function test_multi_image_replicate_models_carry_several_references(): void
    {
        foreach (['bytedance/seedream-5-lite', 'google/nano-banana-2', 'google/nano-banana'] as $model) {
            config()->set('cubfable.ai.models.image.replicate', $model);

            $this->assertSame(6, $this->policy->budget(), "{$model} should read a multi-image array");
        }
    }

    public function test_kontext_style_editors_take_a_single_source_image(): void
    {
        config()->set('cubfable.ai.models.image.replicate', 'black-forest-labs/flux-kontext-pro');

        $this->assertSame(1, $this->policy->budget());
    }
}
