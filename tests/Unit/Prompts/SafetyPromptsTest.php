<?php

namespace Tests\Unit\Prompts;

use App\Services\Prompts\SafetyPrompts;
use PHPUnit\Framework\TestCase;

class SafetyPromptsTest extends TestCase
{
    public function test_the_rewrite_instruction_never_invents_body_descriptions_for_children(): void
    {
        $instruction = (new SafetyPrompts)->rephraseInstruction('a child near a grandfather clock');

        // Replacing "child" with body-shape language ("slender build",
        // "delicate features") is exactly what strict filters flag hardest;
        // names and neutral words are the safe substitution.
        $this->assertStringContainsString('NEVER describe age, body, build, stature or proportions', $instruction);
        $this->assertStringNotContainsString('describe height, build and proportions instead', $instruction);

        // Keyword filters read words, not grammar: innocent phrases that
        // pattern-match badly must be reworded too.
        $this->assertStringContainsString('keyword filter could misread', $instruction);

        $this->assertStringContainsString('a child near a grandfather clock', $instruction);
    }
}
