<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Idempotent seed for the story templates ("ideas").
 *
 * The six original starter themes live inline below; the larger idea catalog is
 * loaded from database/data/templates.json (generated content, one entry per
 * idea, each with a ready-to-use text-to-image cover prompt). Templates are
 * upserted by title, so running this repeatedly is safe and preserves existing
 * ids (and any books that reference them).
 */
class TemplateSeeder extends Seeder
{
    /**
     * Gradient palette for the generated idea covers. Picked deterministically
     * per idea so re-seeding keeps the same colors.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const PALETTE = [
        ['#2f855a', '#1c4532'],
        ['#2b6cb0', '#1a365d'],
        ['#4c51bf', '#1a202c'],
        ['#dd6b20', '#7b341e'],
        ['#319795', '#1d4044'],
        ['#d53f8c', '#702459'],
        ['#805ad5', '#322659'],
        ['#3182ce', '#2a4365'],
        ['#38a169', '#22543d'],
        ['#c53030', '#742a2a'],
        ['#d69e2e', '#744210'],
        ['#00897b', '#004d40'],
        ['#5a67d8', '#3c366b'],
        ['#b83280', '#521b41'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $allTemplates = array_merge($this->starterTemplates(), $this->ideaTemplates());

        foreach ($allTemplates as $template) {
            // cover_image_url is always recomputed by cover(): it picks up the
            // checked-in real image if one exists at public/images/templates,
            // otherwise falls back to the placeholder, so re-seeding safely
            // syncs cover art as more real images are added over time.
            Template::query()->updateOrCreate(
                ['title' => $template['title']],
                $template,
            );
        }
    }

    /**
     * The original six starter themes, each with its own cover image prompt.
     *
     * @return list<array<string, mixed>>
     */
    private function starterTemplates(): array
    {
        return [
            [
                'title' => 'The Whispering Forest',
                'description' => 'A gentle woodland adventure where your child befriends the shy creatures of an enchanted forest.',
                'theme' => 'forest',
                'age_min' => 3,
                'age_max' => 7,
                'cover_image_url' => $this->cover('The Whispering Forest', '#2f855a', '#1c4532'),
                'page_count' => 6,
                'life_lessons' => ['Kindness', 'Courage', 'Friendship'],
                'art_styles' => ['watercolor', 'storybook', 'pencil-sketch'],
                'subjects' => ['animals', 'nature', 'adventure'],
                'fonts' => ['playful', 'classic', 'handwritten'],
                'image_prompt' => 'Portrait cover with clean top margin. A sunlit enchanted forest of tall mossy trees and floating fireflies, a curious young child in a cozy coat gently greeting a shy deer and small woodland creatures. Soft watercolor washes, dappled golden light, warm green and amber palette, gentle and magical mood.',
            ],
            [
                'title' => 'Pirates of the Sapphire Sea',
                'description' => 'Hoist the sails! Your little captain leads a brave crew across the waves in search of hidden treasure.',
                'theme' => 'pirates',
                'age_min' => 4,
                'age_max' => 8,
                'cover_image_url' => $this->cover('Pirates of the Sapphire Sea', '#2b6cb0', '#1a365d'),
                'page_count' => 6,
                'life_lessons' => ['Teamwork', 'Honesty', 'Bravery'],
                'art_styles' => ['cartoon', 'digital-art', 'storybook'],
                'subjects' => ['adventure', 'ocean', 'treasure'],
                'fonts' => ['bold', 'playful', 'classic'],
                'image_prompt' => 'Portrait cover with clean top margin. A small wooden pirate ship sailing a sparkling sapphire sea under a bright sky, a brave young child captain at the wheel with a tiny cheerful crew, seagulls overhead and a treasure island on the horizon. Bold cartoon style, vivid blues and gold, adventurous mood.',
            ],
            [
                'title' => 'Voyage to the Stars',
                'description' => 'Blast off into space, hop between glittering planets, and discover that the biggest dreams are worth reaching for.',
                'theme' => 'space',
                'age_min' => 4,
                'age_max' => 9,
                'cover_image_url' => $this->cover('Voyage to the Stars', '#4c51bf', '#1a202c'),
                'page_count' => 6,
                'life_lessons' => ['Curiosity', 'Perseverance', 'Wonder'],
                'art_styles' => ['digital-art', 'cartoon', 'watercolor'],
                'subjects' => ['space', 'science', 'exploration'],
                'fonts' => ['bold', 'classic', 'playful'],
                'image_prompt' => 'Portrait cover with clean top margin. A young astronaut child floating joyfully among glittering planets and stars beside a friendly little rocket, the blue Earth glowing far below. Deep indigo and violet palette with twinkling highlights, smooth digital illustration, dreamy and wondrous mood.',
            ],
            [
                'title' => 'The Little Kitchen',
                'description' => 'A cozy tale of mixing, tasting, and sharing, where your child cooks up something wonderful for the whole family.',
                'theme' => 'kitchen',
                'age_min' => 3,
                'age_max' => 7,
                'cover_image_url' => $this->cover('The Little Kitchen', '#dd6b20', '#7b341e'),
                'page_count' => 5,
                'life_lessons' => ['Patience', 'Sharing', 'Creativity'],
                'art_styles' => ['watercolor', 'cartoon', 'storybook'],
                'subjects' => ['cooking', 'family', 'food'],
                'fonts' => ['handwritten', 'playful', 'classic'],
                'image_prompt' => 'Portrait cover with clean top margin. A cozy sunlit kitchen where a young child in an apron happily mixes batter in a big bowl, a soft cloud of flour in the air and whisks and bowls nearby. Warm watercolor style, soft orange and cream palette, homely and loving mood.',
            ],
            [
                'title' => 'Land of the Dinosaurs',
                'description' => 'Stomp back in time to a world of friendly giants, where your child learns that even the smallest explorer can be brave.',
                'theme' => 'dinosaurs',
                'age_min' => 4,
                'age_max' => 8,
                'cover_image_url' => $this->cover('Land of the Dinosaurs', '#319795', '#1d4044'),
                'page_count' => 6,
                'life_lessons' => ['Bravery', 'Curiosity', 'Respect for nature'],
                'art_styles' => ['cartoon', 'digital-art', 'pencil-sketch'],
                'subjects' => ['dinosaurs', 'adventure', 'prehistory'],
                'fonts' => ['bold', 'playful', 'classic'],
                'image_prompt' => 'Portrait cover with clean top margin. A lush prehistoric valley with friendly smiling dinosaurs, a small brave child explorer waving up at a gentle long-necked dinosaur, green ferns and a distant misty volcano. Bright cartoon style, teal and green palette, fun and adventurous mood.',
            ],
            [
                'title' => 'Over the Rainbow',
                'description' => 'A bright, magical journey across a rainbow bridge that helps your child believe in themselves no matter the weather.',
                'theme' => 'rainbow',
                'age_min' => 2,
                'age_max' => 6,
                'cover_image_url' => $this->cover('Over the Rainbow', '#d53f8c', '#702459'),
                'page_count' => 5,
                'life_lessons' => ['Hope', 'Kindness', 'Self-belief'],
                'art_styles' => ['watercolor', 'storybook', 'digital-art'],
                'subjects' => ['magic', 'colors', 'feelings'],
                'fonts' => ['handwritten', 'playful', 'classic'],
                'image_prompt' => 'Portrait cover with clean top margin. A glowing rainbow bridge arching across a soft sky, a hopeful young child stepping onto it toward a magical land of fluffy clouds and floating balloons. Dreamy watercolor style, pastel multicolor palette, uplifting and joyful mood.',
            ],
        ];
    }

    /**
     * The generated idea catalog (one entry per idea, with a cover image prompt).
     *
     * @return list<array<string, mixed>>
     */
    private function ideaTemplates(): array
    {
        /** @var list<array{title: string, description: string, theme: string, ageMin: int, ageMax: int, pageCount: int, lifeLessons: list<string>, artStyles: list<string>, subjects: list<string>, fonts: list<string>, imagePrompt: string}> $ideas */
        $ideas = json_decode(File::get(database_path('data/templates.json')), true);

        return array_map(function (array $idea): array {
            [$from, $to] = $this->paletteFor($idea['theme'].$idea['title']);

            return [
                'title' => $idea['title'],
                'description' => $idea['description'],
                'theme' => $idea['theme'],
                'age_min' => $idea['ageMin'],
                'age_max' => $idea['ageMax'],
                'cover_image_url' => $this->cover($idea['title'], $from, $to),
                'page_count' => $idea['pageCount'],
                'life_lessons' => $idea['lifeLessons'],
                'art_styles' => $idea['artStyles'],
                'subjects' => $idea['subjects'],
                'fonts' => $idea['fonts'],
                'image_prompt' => $idea['imagePrompt'],
            ];
        }, $ideas);
    }

    /**
     * The real cover art if it has been generated and checked into
     * public/images/templates/{slug}.jpg, otherwise a self-contained SVG
     * placeholder (no network needed) until one exists.
     */
    private function cover(string $title, string $from, string $to): string
    {
        $realCover = 'images/templates/'.Str::slug($title).'.jpg';

        if (File::exists(public_path($realCover))) {
            return '/'.$realCover;
        }

        return $this->placeholderCover($title, $from, $to);
    }

    /**
     * A self-contained SVG cover (no network needed) used as a placeholder
     * until a real cover image is generated from the idea's image prompt.
     */
    private function placeholderCover(string $title, string $from, string $to): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$from.'"/><stop offset="1" stop-color="'.$to.'"/>'
            .'</linearGradient></defs>'
            .'<rect width="800" height="600" fill="url(#g)"/>'
            .'<rect x="40" y="40" width="720" height="520" rx="28" fill="rgba(255,255,255,0.12)"/>'
            .'<text x="400" y="318" font-family="Georgia, \'Times New Roman\', serif" font-size="52" '
            .'font-weight="700" fill="#ffffff" text-anchor="middle">'.$this->escapeXml($title).'</text>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Pick a gradient for an idea cover, matching the original JS hash
     * ((h * 31 + charCode) >>> 0) so re-seeding keeps the same colors.
     *
     * @return array{0: string, 1: string}
     */
    private function paletteFor(string $key): array
    {
        $hash = 0;
        $length = strlen($key);

        for ($i = 0; $i < $length; $i++) {
            $hash = ($hash * 31 + ord($key[$i])) & 0xFFFFFFFF;
        }

        return self::PALETTE[$hash % count(self::PALETTE)];
    }

    private function escapeXml(string $value): string
    {
        return strtr($value, [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;',
            "'" => '&apos;',
            '"' => '&quot;',
        ]);
    }
}
