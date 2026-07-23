<?php

namespace App\Services\Prompts;

/**
 * The single owner of everything per-art-style. Each selectable style is a
 * four-part record used by every image prompt:
 *
 * - descriptor: medium-first opening line (medium, surface/texture, line
 *   quality, lighting, one signature cue no neighbor style shares).
 * - antiDrift: what this style is NOT - the concrete failure modes image
 *   engines drift into (3D creeping into 2D styles, photorealism creeping
 *   in when a real photo travels as reference).
 * - reinforcement: one closing line that binds characters, props and
 *   background into the same style.
 * - adaptation: how to translate a person from a reference image into the
 *   style; only emitted when a reference actually travels.
 *
 * Keyed by the ArtStyle enum values plus the legacy styles kept so older
 * books still render; unknown keys fall back to 'storybook'.
 */
class ArtStyleLibrary
{
    /**
     * @var array<string, array{descriptor: string, antiDrift: list<string>, reinforcement: string, adaptation: string}>
     */
    private const STYLES = [
        '3d-animation' => [
            'descriptor' => 'a still frame from a modern 3D animated preschool film, fully rendered CGI: rounded simplified character models with soft toy-like surfacing, big warmly expressive eyes, gentle subsurface-lit skin, soft cinematic global illumination, cheerful pastel-leaning colors, cozy whimsical shapes',
            'antiDrift' => [
                'Strictly 3D rendered: not flat 2D, no drawn outlines, no cel shading.',
                'Stylized characters only: no photorealistic humans, no realistic skin pores or individual hair strands.',
            ],
            'reinforcement' => 'Characters, props and background all belong to the same soft 3D animated film, like one frame from one movie.',
            'adaptation' => 'The reference photos only show who each character is: design that person as a brand-new soft 3D animated film character, the way an animation studio designs a character from someone - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'cartoon' => [
            'descriptor' => "modern flat 2D cartoon like today's hit preschool shows: chunky simple shapes with thick clean confident outlines, bold flat color fills over a subtle paper-grain, minimal cel shading, big readable expressions, cheerful simplified backgrounds with playful shape language",
            'antiDrift' => [
                'Strictly flat 2D: no 3D rendering, no realistic volume, no soft airbrushed shading.',
                'No photorealism and no painterly texture - crisp flat fills inside clean outlines.',
            ],
            'reinforcement' => 'Characters, props and background all use the same flat 2D cartoon language, like one frame from one show.',
            'adaptation' => 'The reference photos only show who each character is: design that person as a brand-new flat 2D cartoon character in bold clean outlines - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'storybook' => [
            'descriptor' => 'classic storybook oil painting: rich layered brushwork with glazed depth, warm golden-hour light and deep cozy shadows, old-master fairy-tale atmosphere, intricately detailed backgrounds worth lingering over, timeless picture-book cover quality',
            'antiDrift' => [
                'A painted image everywhere: visible brushwork, no smooth digital gradients, no vector flatness.',
                'No photographic realism - painterly, warm and handmade.',
            ],
            'reinforcement' => 'Every part of the image is painted with the same oils on the same canvas, one continuous painting.',
            'adaptation' => 'The reference photos only show who each character is: paint that person as a brand-new oil-painted storybook character in warm painterly brushwork - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'watercolor' => [
            'descriptor' => 'true watercolor on cold-press paper: loose wet-on-wet washes with blooming pigment edges and granulation, the white of the paper breathing through highlights, a fine light pencil underdrawing peeking through, airy luminous palette, spontaneous hand-painted charm',
            'antiDrift' => [
                'Everything stays watercolor: translucent washes, soft blooms, paper texture showing through.',
                // Positive texture instructions beat negations on models that
                // parse "no ..." weakly (e.g. Seedream), which otherwise render
                // a clean, digital-smooth version under a watercolor prompt.
                // Avoid watercolor jargon models take literally: "cauliflower
                // blooms" painted actual cauliflowers, "deckled edges" drew a
                // torn-paper border around the artwork.
                'Matte, grainy finish with visible cold-press paper tooth; soft feathered wash edges; gentle pigment blooms and backruns; dry-brush scumbling; colours sit in translucent layers, never opaque.',
            ],
            'reinforcement' => 'One artist painted the whole scene in the same watercolor session: same washes, same paper, same light touch.',
            'adaptation' => 'The reference photos only show who each character is: paint that person as a brand-new watercolor character in loose translucent washes - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'soft-digital' => [
            'descriptor' => 'polished soft-edged digital storybook illustration: smooth gentle shading with a fine matte grain, rounded friendly shapes, warm golden domestic light, tidy lovingly detailed environments, calm pastel-leaning palette, heartwarming modern picture-book feel',
            'antiDrift' => [
                'Everything keeps the same soft polished digital-illustration finish: gentle gradients, clean soft edges, a quiet matte texture over every surface.',
                'Soft and illustrative throughout, never photorealistic, never a harsh 3D render, never rough traditional media.',
            ],
            'reinforcement' => 'Every element shares the same soft digital illustration: one artist, one warm light, one cohesive picture-book scene.',
            'adaptation' => 'The reference photos only show who each character is: redraw that person as a soft digital storybook character - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'crayon' => [
            'descriptor' => "child's crayon and colored-pencil drawing: waxy expressive strokes with visible pressure changes, construction-paper tooth showing through the color, wobbly charming outlines, bright primary colors filled in with joyful scribble energy",
            'antiDrift' => [
                'Every surface is hand-colored in crayon or pencil, with visible waxy strokes - no flat digital fills.',
                'No 3D rendering, no realistic shading, no photorealism.',
            ],
            'reinforcement' => 'The whole picture looks drawn by one enthusiastic young artist with the same crayon box.',
            'adaptation' => 'The reference photos only show who each character is: draw that person as a brand-new crayon-drawn character in waxy hand strokes - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'clay-animation' => [
            'descriptor' => 'handmade stop-motion claymation frame: characters sculpted in plasticine with visible fingerprints and tool marks, a miniature set built from felt, wire and painted card, soft studio key light casting real tiny shadows, shallow macro-lens depth of field on a little stage',
            'antiDrift' => [
                'Everything is physically sculpted and photographed on a miniature stage - no 2D drawing, no digitally smooth CGI.',
                'Characters keep visible plasticine texture and fingerprints; no realistic human skin.',
            ],
            'reinforcement' => 'The whole frame is one physical claymation set: same plasticine, same studio light, same tiny stage.',
            'adaptation' => 'The reference photos only show who each character is: sculpt that person as a brand-new plasticine stop-motion character - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'felt-craft' => [
            'descriptor' => 'needle-felted wool scene: every surface made of fuzzy carded wool with hand-stitched embroidery details, button and bead accents, soft plush-toy proportions, cozy nursery lighting, a handmade heirloom photographed in a lightbox',
            'antiDrift' => [
                'Every surface reads as real wool, stitches and fabric - no 2D drawing, no smooth CGI.',
                'No realistic human skin or hair; faces are soft felted fabric.',
            ],
            'reinforcement' => 'The whole scene is one handmade felt diorama: same wool, same stitches, same cozy light.',
            'adaptation' => 'The reference photos only show who each character is: craft that person as a brand-new needle-felted wool doll in felt and stitches - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'paper-lightbox' => [
            'descriptor' => 'layered paper-cut lightbox diorama: crisp scalpel-cut card shapes stacked into deep glowing layers, warm light spilling between the layers, soft cast shadows giving real physical depth, delicate cut-out details in every silhouette, magical shadow-box night-light feel',
            'antiDrift' => [
                'Everything is cut from layered paper: flat card shapes with real depth between layers, no rounded 3D character rendering.',
                'No painterly brushwork, no photorealism; crisp cut edges everywhere.',
            ],
            'reinforcement' => 'The whole image is one glowing paper lightbox: same card layers, same warm light between them.',
            'adaptation' => 'The reference photos only show who each character is: cut that person as a brand-new layered paper character in crisp card silhouettes - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'soft-anime' => [
            'descriptor' => 'hand-painted anime film style: lush painterly background art with soft gouache textures, characters in gentle cel shading with delicate linework and expressive eyes, nostalgic golden light and drifting atmosphere, the quiet wonder of a beloved animated classic',
            'antiDrift' => [
                '2D anime cel characters over painterly backgrounds - no 3D rendering, no western cartoon proportions.',
                'No photorealism; delicate line art and soft cel shadows.',
            ],
            'reinforcement' => 'Every element belongs to the same hand-painted anime film: same linework, same golden light.',
            'adaptation' => 'The reference photos only show who each character is: draw that person as a brand-new gentle anime film character in delicate line art and cel shading - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
        'comic-book' => [
            'descriptor' => "kids' comic-book splash art: confident black ink outlines with varied line weight, vibrant cel shading with halftone-dot accents, dynamic action-friendly composition, energetic color pops, printed-comic page texture",
            'antiDrift' => [
                'Bold inked 2D comic art - no 3D rendering, no soft painterly blending.',
                'No photorealism; every shape carries a confident black ink outline.',
            ],
            'reinforcement' => 'The whole image sits on one printed comic page: same inks, same halftones, same energy.',
            'adaptation' => 'The reference photos only show who each character is: ink that person as a brand-new comic character in bold outlines and cel shading - never photographic, and dressed to fit the scene, never in the clothes from the photo.',
        ],
    ];

    /**
     * Legacy styles kept so older books still render; they get the generic
     * anti-drift treatment below instead of bespoke records.
     *
     * @var array<string, string>
     */
    private const LEGACY_DESCRIPTORS = [
        'gouache' => 'matte gouache painting, opaque rich pigments, confident visible brushstrokes, warm storybook palette, painterly texture',
        'sticker-art' => 'glossy die-cut sticker style, thick rounded white outlines, bright saturated colors, simple cute shapes, soft drop shadow',
        'collage' => 'paper-cut collage style, layered torn and cut textured paper, mixed-media handmade feel, soft shadows between the paper layers',
        'block-world' => 'blocky voxel 3D world, cubic stylized characters and props, playful building-block aesthetic, clean bright lighting',
        'geometric' => 'bold flat geometric illustration, simple clean shapes, mid-century-modern vector art, a limited harmonious palette, crisp edges, tasteful negative space',
        'pencil-sketch' => "pencil sketch with light color wash, hand-drawn look, soft texture, illustrated children's book",
        'digital-art' => "digital illustration, clean lines, vibrant colors, modern children's book style",
    ];

    private const GENERIC_REINFORCEMENT = 'Characters, props and background all share this exact same illustration style throughout.';

    private const GENERIC_ADAPTATION = 'The reference photos only show who each character is: draw that person as a brand-new character in this exact illustration style - never photographic, and dressed to fit the scene, never in the clothes from the photo.';

    public function descriptor(string $style): string
    {
        return self::STYLES[$style]['descriptor']
            ?? self::LEGACY_DESCRIPTORS[$style]
            ?? self::STYLES['storybook']['descriptor'];
    }

    /**
     * @return list<string>
     */
    public function antiDriftHints(string $style): array
    {
        return self::STYLES[$style]['antiDrift']
            ?? (isset(self::LEGACY_DESCRIPTORS[$style]) ? [] : self::STYLES['storybook']['antiDrift']);
    }

    public function reinforcementLine(string $style): string
    {
        return self::STYLES[$style]['reinforcement']
            ?? (isset(self::LEGACY_DESCRIPTORS[$style]) ? self::GENERIC_REINFORCEMENT : self::STYLES['storybook']['reinforcement']);
    }

    public function referenceAdaptationLine(string $style): string
    {
        return self::STYLES[$style]['adaptation']
            ?? (isset(self::LEGACY_DESCRIPTORS[$style]) ? self::GENERIC_ADAPTATION : self::STYLES['storybook']['adaptation']);
    }

    /**
     * @return list<string>
     */
    public function knownStyles(): array
    {
        return array_merge(array_keys(self::STYLES), array_keys(self::LEGACY_DESCRIPTORS));
    }
}
