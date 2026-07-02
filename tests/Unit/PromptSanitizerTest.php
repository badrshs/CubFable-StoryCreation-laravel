<?php

namespace Tests\Unit;

use App\Services\AI\PromptSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PromptSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function replacementProvider(): array
    {
        return [
            'n years old' => ['a 5 years old hero', 'a young hero'],
            'n year old hyphenated' => ['a 7-year-old explorer', 'a young explorer'],
            'n year-old mixed separators' => ['an 8 year-old painter', 'an young painter'],
            'aged n' => ['a wizard aged 9', 'a wizard young'],
            'age n' => ['age 6 adventurer', 'young adventurer'],
            "children's" => ["a children's tale", 'a storybook tale'],
            'children' => ['three children playing', 'three small people playing'],
            "child's" => ["the child's smile", "the young character's smile"],
            'child' => ['a child in the forest', 'a young character in the forest'],
            'kids' => ['the kids laugh', 'the small people laugh'],
            'kid' => ['a brave kid', 'a brave young character'],
            'boy' => ['a boy with a kite', 'a young character with a kite'],
            'girl' => ['a girl in red boots', 'a young character in red boots'],
            'toddler' => ['a curious toddler', 'a curious small character'],
            'infant' => ['a sleeping infant', 'a sleeping small character'],
            'baby' => ['a baby dragon', 'a small character dragon'],
            'minor' => ['a minor watching the parade', 'a young character watching the parade'],
        ];
    }

    #[DataProvider('replacementProvider')]
    public function test_replaces_each_flagged_term(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PromptSanitizer)->sanitize($input));
    }

    public function test_replacements_are_case_insensitive(): void
    {
        $this->assertSame(
            'A young character and a small character dragon',
            (new PromptSanitizer)->sanitize('A Child and a BABY dragon'),
        );
    }

    public function test_replacements_are_global(): void
    {
        $this->assertSame(
            'a young character meets another young character',
            (new PromptSanitizer)->sanitize('a child meets another child'),
        );
    }

    public function test_plural_forms_run_before_singular_forms(): void
    {
        $this->assertSame(
            'small people and a young character',
            (new PromptSanitizer)->sanitize('children and a child'),
        );
    }

    public function test_word_boundaries_leave_larger_words_untouched(): void
    {
        $this->assertSame(
            'a boyish grin near the girlfriend statue in Babylon',
            (new PromptSanitizer)->sanitize('a boyish grin near the girlfriend statue in Babylon'),
        );
    }

    public function test_leaves_visual_detail_intact(): void
    {
        $this->assertSame(
            'watercolor illustration of a young character with curly red hair, green raincoat and yellow boots',
            (new PromptSanitizer)->sanitize('watercolor illustration of a child with curly red hair, green raincoat and yellow boots'),
        );
    }
}
