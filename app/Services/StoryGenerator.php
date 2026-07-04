<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Enums\StoryLanguage;
use App\Models\Book;
use App\Models\Character;
use App\Models\Page;
use App\Models\Template;
use App\Services\AI\AiManager;
use App\Services\AI\AppearanceDescriber;
use App\Services\AI\GeneratedImage;
use App\Services\AI\ImageReference;
use App\Services\AI\PromptLogContext;
use App\Services\AI\SafeImageGenerator;
use App\Services\AI\UsageCollector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoryGenerator
{
    /**
     * @var array<string, string>
     */
    private const ART_STYLE_PROMPTS = [
        '3d-animation' => 'glossy 3D animated film style like a modern Pixar or DreamWorks movie, soft global illumination, rounded volumetric forms, subsurface-scattered skin, big expressive eyes, gentle depth of field',
        'watercolor' => "soft watercolor illustration, loose translucent washes, bleeding pigments, visible cold-press paper texture, gentle warm palette, hand-painted children's picture book",
        'geometric' => 'bold flat geometric illustration, simple clean shapes, mid-century-modern vector art, a limited harmonious palette, crisp edges, tasteful negative space',
        'clay-animation' => 'stop-motion claymation style, sculpted plasticine characters, soft studio lighting, subtle fingerprint and tooling marks, tactile handmade look',
        'sticker-art' => 'glossy die-cut sticker style, thick rounded white outlines, bright saturated colors, simple cute shapes, soft drop shadow',
        'comic-book' => 'comic book and graphic-novel style, bold black ink outlines, dynamic cel shading, halftone dot shading, vivid colors, energetic composition',
        'gouache' => 'matte gouache painting, opaque rich pigments, confident visible brushstrokes, warm storybook palette, painterly texture',
        'soft-anime' => 'gentle anime cel-shaded style, soft ambient shading, expressive large eyes, delicate clean linework, dreamy pastel lighting',
        'block-world' => 'blocky voxel 3D world, cubic stylized characters and props, playful building-block aesthetic, clean bright lighting',
        'collage' => 'paper-cut collage style, layered torn and cut textured paper, mixed-media handmade feel, soft shadows between the paper layers',
        'storybook' => 'classic storybook illustration, detailed oil painting, warm golden light, fairy tale style',
        'crayon' => "child's crayon and colored-pencil drawing, waxy expressive strokes, construction-paper texture, naive charming lines, bright primary colors",
        'felt-craft' => 'needle-felted wool and fabric craft scene, soft fuzzy textures, hand-stitched details, cozy handmade toy look, gentle studio lighting',
        // Legacy styles kept so older books still render.
        'cartoon' => 'vibrant cartoon illustration, bold outlines, bright cheerful colors, animated movie style',
        'pencil-sketch' => "pencil sketch with light color wash, hand-drawn look, soft texture, illustrated children's book",
        'digital-art' => "digital illustration, clean lines, vibrant colors, modern children's book style",
    ];

    /**
     * English-only cover subtitles, keyed by theme. The cover title is always
     * English regardless of the story language.
     *
     * @var array<string, string>
     */
    private const COVER_SUBTITLES = [
        'forest' => 'and the Whispering Forest',
        'pirates' => 'and the Sapphire Sea',
        'space' => 'and the Voyage to the Stars',
        'kitchen' => 'and the Little Kitchen',
        'dinosaurs' => 'and the Land of Giants',
        'rainbow' => 'and the Rainbow Bridge',
    ];

    /**
     * Read-aloud craft calibrated per age band, injected into the author
     * prompt so a 2-4 book and an 8-10 book stop sounding the same.
     *
     * @var array<string, string>
     */
    private const AGE_WRITING_RULES = [
        '2-4' => '1-2 very short sentences per page, simple everyday words, lots of repetition and sound words (Boom! Splash!), name objects and colors.',
        '4-6' => '2-3 short sentences per page, playful rhythm, simple cause and effect, gentle humor, some sound words.',
        '6-8' => '3-4 sentences per page, richer vocabulary with context clues, a real small challenge and a clever solution, light humor.',
        '8-10' => '3-5 sentences per page, vivid vocabulary, deeper feelings and a satisfying arc, wit welcome; never talk down to the reader.',
    ];

    public function __construct(
        private AiManager $ai,
        private SafeImageGenerator $safeImages,
        private AppearanceDescriber $appearanceDescriber,
        private BookImageStorage $images,
        private UsageCollector $usage,
    ) {}

    /**
     * Run the full generation pipeline: story text, cover, then every page
     * illustration sequentially (to stay within rate limits).
     */
    public function generateStorybook(Book $book): void
    {
        try {
            $book->update(['status' => BookStatus::Generating]);

            $pageCount = Template::query()->findOrFail($book->template_id)->page_count;

            $cast = $this->castFor($book);

            if ($cast->isEmpty()) {
                throw new RuntimeException('Book has no characters');
            }

            $this->ensureCast($cast, $book->art_style);
            $main = $this->mainCharacter($cast);

            if ($main === null) {
                throw new RuntimeException('Book has no characters');
            }

            $pages = $book->pages()->get()->all();

            if ($pages === []) {
                // The book bible: localized text plus English art direction
                // (world, color script, motif, per-page shots).
                $blueprint = $this->generateStoryBlueprint($book, $pageCount, $cast, $main);

                // Persist the bible before any image work so the cover subtitle
                // and page regenerations can reuse the same world and lighting.
                $bible = array_filter(Arr::except($blueprint, ['pages']), fn ($value): bool => $value !== null);
                $book->update(['story_bible' => $bible !== [] ? $bible : null]);

                foreach ($blueprint['pages'] as $index => $storyPage) {
                    $pages[] = Page::query()->create([
                        'book_id' => $book->id,
                        'page_number' => $index + 1,
                        'text' => $storyPage['text'],
                        'scene' => $storyPage['scene'],
                        'art_direction' => $storyPage['artDirection'],
                        'image_path' => null,
                        'status' => PageStatus::Generating,
                    ]);
                }
            }
            // Existing pages mean an interrupted run being resumed: the story
            // and bible are already persisted, only missing images remain.

            // One canonical stylized rendition of the hero. The cover and
            // every page copy it, so the photo-to-illustration jump happens
            // once here instead of independently on every image. In photo
            // mode the original upload leads instead and no sheet is made.
            // A sheet that survived an interrupted run is reused as-is.
            $anchor = $this->anchorsWithSheet($main)
                ? ($this->anchorFor($book) ?? $this->prepareHeroSheet($book, $main))
                : null;

            // Cover (generated WITH the title; the decorative frame is added
            // in the UI). Skipped when a previous run already produced one.
            if ($book->cover_image_path === null) {
                $this->storeCover($book, $main, $anchor);
            }

            // Pages (sequential to stay within rate limits); pages that
            // already have their illustration are never re-billed.
            foreach ($pages as $page) {
                if ($page->status === PageStatus::Complete && $page->image_path !== null) {
                    continue;
                }

                try {
                    $this->storePageIllustration($page, $book, $cast, $main, $anchor);
                } catch (Throwable $exception) {
                    Log::error("Failed to generate image for page {$page->page_number}: {$exception->getMessage()}");
                    $page->update(['status' => PageStatus::Failed]);
                }
            }

            $book->update(['status' => BookStatus::Complete]);
        } catch (Throwable $exception) {
            Log::error("Storybook generation failed: {$exception->getMessage()}");
            $book->update(['status' => BookStatus::Failed]);
        } finally {
            $this->usage->flush($book->id);
        }
    }

    /**
     * Regenerate a single page's illustration. Failures flip only that page.
     */
    public function regeneratePageIllustration(Page $page, Book $book): void
    {
        try {
            $cast = $this->castFor($book);
            $this->ensureCast($cast, $book->art_style);
            $main = $this->mainCharacter($cast);

            if ($main === null) {
                throw new RuntimeException('Book has no characters');
            }

            $this->storePageIllustration($page, $book, $cast, $main, $this->anchorsWithSheet($main) ? $this->anchorFor($book) : null);
        } catch (Throwable $exception) {
            Log::error("Failed to regenerate page {$page->id}: {$exception->getMessage()}");
            $page->update(['status' => PageStatus::Failed]);
        } finally {
            $this->usage->flush($book->id);
        }
    }

    /**
     * Regenerate only the cover image (with its title) for an existing book.
     */
    public function regenerateCover(Book $book): void
    {
        $book->update(['cover_status' => 'generating']);

        $failure = null;

        try {
            $cast = $this->castFor($book);
            $this->ensureCast($cast, $book->art_style);
            $main = $this->mainCharacter($cast);

            if ($main === null) {
                throw new RuntimeException('Book has no characters');
            }

            $this->storeCover($book, $main, $this->anchorsWithSheet($main) ? $this->anchorFor($book) : null);
            $book->update(['cover_status' => null]);
        } catch (Throwable $exception) {
            Log::error("Failed to regenerate cover for book {$book->id}: {$exception->getMessage()}");
            $book->update(['cover_status' => 'failed']);
            $failure = $exception;
        } finally {
            $this->usage->flush($book->id);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Ensure every cast member has a cached appearance (derived from photo or
     * text), stored on the reusable character row. Per-member failures are
     * logged and swallowed.
     *
     * @param  Collection<int, Character>  $cast
     */
    private function ensureCast(Collection $cast, string $artStyle): void
    {
        foreach ($cast as $member) {
            if ($member->appearance !== null && trim($member->appearance) !== '') {
                continue;
            }

            $appearance = '';

            try {
                $appearance = $this->hasUsablePhoto($member)
                    ? trim($this->appearanceDescriber->describe((new ImageReference((string) $member->photo_path))->dataUrl()))
                    : trim($this->describeCharacterFromText($member, $artStyle));
            } catch (Throwable $exception) {
                Log::warning("[ai] appearance for \"{$member->name}\" failed: {$exception->getMessage()}");
            }

            if ($appearance !== '') {
                $member->appearance = $appearance;
                $member->save();
            }
        }
    }

    /**
     * Build a detailed appearance for a character that has no photo, from its
     * name, role, and any user notes.
     */
    private function describeCharacterFromText(Character $member, string $artStyle): string
    {
        $roleClause = $member->role !== null && $member->role !== '' ? " ({$member->role})" : '';
        $notesClause = $member->description !== null && $member->description !== '' ? " - notes: {$member->description}" : '';

        $prompt = <<<PROMPT
For a children's storybook in the "{$artStyle}" art style, write a DETAILED, SPECIFIC physical appearance for one character, to be used as a fixed reference so an illustrator draws them IDENTICALLY in every picture.

Character: {$member->name}{$roleClause}{$notesClause}

Include ALL of: hair (color/length/style); facial hair (beard and/or mustache shape/length/color, or "clean-shaven"); eyebrows, eye color and shape, skin tone, face shape, body build; a signature outfit (top, bottom, footwear, each with specific colors); accessories (or "none"). Do NOT mention age. Return ONLY the description text, no preamble.
PROMPT;

        return trim($this->ai->generateText($prompt));
    }

    /**
     * One call, two jobs: the author writes the localized story and the art
     * director plans how every page looks (a reusable world, a lighting arc,
     * per-page shots, a hidden motif, a bespoke cover subtitle).
     *
     * @param  Collection<int, Character>  $cast
     * @return array{subtitle: ?string, world: ?string, motif: ?string, refrain: ?string, colorScript: ?list<string>, cover: ?array<string, string>, pages: list<array{text: string, scene: string, artDirection: ?array<string, string>}>}
     */
    private function generateStoryBlueprint(Book $book, int $pageCount, Collection $cast, Character $main): array
    {
        $langName = StoryLanguage::tryFrom($book->language)?->label() ?? 'English';

        $others = $cast
            ->reject(fn (Character $character): bool => $character->id === $main->id)
            ->map(fn (Character $character): string => $character->name.($character->role !== null && $character->role !== '' ? " ({$character->role})" : ''))
            ->implode(', ');
        $othersText = $others !== '' ? $others : 'none';
        $ageRules = self::AGE_WRITING_RULES[$book->age_range] ?? self::AGE_WRITING_RULES['4-6'];

        $prompt = <<<PROMPT
You are an award-winning children's picture-book author AND the book's art director. Create a complete book plan for a personalized storybook starring {$main->name} (age {$book->age_range}).

Story details:
- Setting / world: {$book->theme}
- What the story is about (the subject - make this central to the plot): {$book->subject}
- Life lesson: {$book->life_lesson}
- Art style: {$book->art_style}
- Additional characters: {$othersText}
- Story language: {$langName}

WRITING RULES:
1. Age calibration for {$book->age_range}: {$ageRules}
2. {$main->name} is the hero and solves the problem through their own idea, courage or kindness - other characters help, they never rescue.
3. Weave the life lesson through choices and their consequences. NEVER state it as a moral; never write "learned that".
4. End odd-numbered pages on a small question or surprise so the child wants to turn the page.
5. Include a short playful refrain (a rhythmic catchphrase or sound words) and repeat it on 2-3 pages, including near the end.
6. Page "text" is written in {$langName}. Everything else (scene, world, subtitle, motif) is ENGLISH regardless of the story language.

ART DIRECTION RULES:
7. "world": 2-3 reusable sentences describing the main location(s) - architecture, colors, props, atmosphere. Every page happens in or near this world.
8. "colorScript": one short lighting/palette note per page (e.g. "warm morning gold"). Across the book the light should travel (e.g. morning to starry night) to mirror the emotional arc.
9. Every page "scene" object needs:
   - "shot": one of: wide establishing / medium / close-up / low angle / over-the-shoulder / bird's eye. Vary them - never the same shot twice in a row; open wide, go close at the emotional peak, pull back warm at the end.
   - "action": what visually happens - specific verbs, who stands where, what hands hold. Name every character present.
   - "expression": {$main->name}'s emotion, positive and fitting the moment (joyful, curious, focused, amazed, cozy, gently brave). Never sad, crying, scared or distressed.
   - "detail": one small memorable prop or micro-event unique to this page.
10. "motif": one tiny visual object (never a main character) hidden somewhere on every page for the child to find.
11. "subtitle": a charming English cover subtitle, at most 6 words.
12. "cover": design the front cover like a bestselling published picture book:
   - "moment": the single most magical, iconic moment of THIS story as cover key art - {$main->name} mid-action and full of wonder (never a static standing pose), with the story's world behind them and room at the top for the title.
   - "titleStyle": how the hand-lettered title should look, themed to the story (materials, colors, tiny ornaments - e.g. letters entwined with ivy, letters built from brass cogs and springs).

Write exactly {$pageCount} pages. Return ONLY this JSON object (no other text):
{"subtitle":"...","world":"...","motif":"...","refrain":"...","colorScript":["one note per page"],"cover":{"moment":"...","titleStyle":"..."},"pages":[{"text":"...","scene":{"shot":"...","action":"...","expression":"...","detail":"..."}}]}
PROMPT;

        $content = $this->ai->generateText($prompt);

        return $this->parseBlueprint($content, $pageCount);
    }

    /**
     * Tolerant blueprint parsing: the full bible object when the model
     * cooperates, graceful degradation to the legacy [{text, scene}] array
     * (bible fields null) when it does not.
     *
     * @return array{subtitle: ?string, world: ?string, motif: ?string, refrain: ?string, colorScript: ?list<string>, cover: ?array<string, string>, pages: list<array{text: string, scene: string, artDirection: ?array<string, string>}>}
     */
    private function parseBlueprint(string $content, int $pageCount): array
    {
        $parsed = null;

        if (preg_match('/\{[\s\S]*\}/', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded) && isset($decoded['pages'])) {
                $parsed = $decoded;
            }
        }

        if ($parsed === null && preg_match('/\[[\s\S]*\]/', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                $parsed = ['pages' => $decoded];
            }
        }

        if ($parsed === null || ! is_array($parsed['pages'] ?? null)) {
            throw new RuntimeException('Invalid story text response');
        }

        $pages = [];

        foreach (array_slice(array_values($parsed['pages']), 0, $pageCount) as $item) {
            if (is_string($item)) {
                $pages[] = ['text' => $item, 'scene' => $item, 'artDirection' => null];

                continue;
            }

            $rawText = is_array($item) ? ($item['text'] ?? null) : null;
            $rawScene = is_array($item) ? ($item['scene'] ?? null) : null;

            if (is_array($rawScene)) {
                $artDirection = [];

                foreach (['shot', 'action', 'expression', 'detail'] as $key) {
                    $value = trim($this->stringify($rawScene[$key] ?? null));

                    if ($value !== '') {
                        $artDirection[$key] = $value;
                    }
                }

                $flatScene = trim(($artDirection['action'] ?? '').' '.($artDirection['detail'] ?? ''));

                $pages[] = [
                    'text' => $this->stringify($rawText),
                    'scene' => $flatScene !== '' ? $flatScene : $this->stringify($rawText),
                    'artDirection' => $artDirection !== [] ? $artDirection : null,
                ];

                continue;
            }

            $pages[] = [
                'text' => $this->stringify($rawText),
                'scene' => $this->stringify($rawScene ?? $rawText),
                'artDirection' => null,
            ];
        }

        $colorScript = null;

        if (is_array($parsed['colorScript'] ?? null)) {
            $colorScript = array_values(array_filter(array_map(
                fn ($note): string => trim($this->stringify($note)),
                $parsed['colorScript'],
            ), fn (string $note): bool => $note !== ''));

            if ($colorScript === []) {
                $colorScript = null;
            }
        }

        $cover = null;

        if (is_array($parsed['cover'] ?? null)) {
            $cover = array_filter([
                'moment' => $this->bibleLine($parsed['cover']['moment'] ?? null, 600),
                'titleStyle' => $this->bibleLine($parsed['cover']['titleStyle'] ?? null, 300),
            ], fn (?string $value): bool => $value !== null);

            if ($cover === []) {
                $cover = null;
            }
        }

        return [
            'subtitle' => $this->bibleLine($parsed['subtitle'] ?? null, 60),
            'world' => $this->bibleLine($parsed['world'] ?? null, 800),
            'motif' => $this->bibleLine($parsed['motif'] ?? null, 200),
            'refrain' => $this->bibleLine($parsed['refrain'] ?? null, 200),
            'colorScript' => $colorScript,
            'cover' => $cover,
            'pages' => $pages,
        ];
    }

    /**
     * A single clean bible value: trimmed, unquoted, length-capped, or null.
     */
    private function bibleLine(mixed $value, int $maxLength): ?string
    {
        $line = trim(trim($this->stringify($value)), "\"'");
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        if ($line === '') {
            return null;
        }

        return mb_substr($line, 0, $maxLength);
    }

    /**
     * Generate and store the hero's character sheet: one canonical stylized
     * rendition of the main character that anchors the cover and every page.
     * Failures are non-fatal; generation simply continues unanchored.
     */
    private function prepareHeroSheet(Book $book, Character $main): ?ImageReference
    {
        try {
            $image = $this->generateHeroSheetImage($book, $main);
            $path = sprintf('books/%d/sheet-%s.png', $book->id, Str::lower(Str::random(8)));

            $this->images->storeGenerated($image->bytes, $path);

            $previous = $book->hero_sheet_path;
            $book->update(['hero_sheet_path' => $path, 'hero_sheet_prompt' => $image->prompt]);

            if ($previous !== $path) {
                $this->images->delete($previous);
            }

            return new ImageReference($path, "{$main->name} (character sheet)");
        } catch (Throwable $exception) {
            Log::warning("Hero sheet generation failed for book {$book->id}, continuing without an anchor: {$exception->getMessage()}");

            return $this->anchorFor($book);
        }
    }

    private function generateHeroSheetImage(Book $book, Character $main): GeneratedImage
    {
        $artStyle = self::ART_STYLE_PROMPTS[$book->art_style] ?? self::ART_STYLE_PROMPTS['storybook'];
        $usePhoto = $this->hasUsablePhoto($main);
        $references = $usePhoto ? [new ImageReference((string) $main->photo_path, $main->name)] : [];

        // Reference OR description, never both (see buildScene).
        $photoNote = $usePhoto
            ? ' Match the child in the attached reference photo faithfully: same face, hairstyle, hair color, skin tone, eye color and build, redrawn in the art style (an illustration, never a photo).'
            : '';
        $appearance = trim((string) $main->appearance);
        $appearanceClause = ! $usePhoto && $appearance !== '' ? " {$main->name}'s appearance: {$appearance}" : '';

        $prompt = <<<PROMPT
{$artStyle}. Character reference sheet for a children's picture book.

A single full-body illustration of {$main->name} standing and facing the viewer in a relaxed, friendly pose with a big happy smile, on a plain soft neutral background.{$photoNote}{$appearanceClause}

Exactly one character and nothing else in the frame. The character fills most of the frame so the face, hair, skin tone, outfit, colors and shoes are all clearly visible. No text, letters, numbers, labels, borders or logos anywhere.
PROMPT;

        return $this->safeImages->generate($prompt, '1024x1536', $references, 'character sheet', new PromptLogContext($book->id, 'character-sheet'));
    }

    /**
     * Whether the hero's identity anchors on a generated character sheet.
     * In photo mode the original upload is the reference instead, so a
     * sheet is only worth making when there is no photo to lean on.
     */
    private function anchorsWithSheet(Character $main): bool
    {
        $preference = strtolower(trim((string) config('cubfable.ai.identity_reference', 'sheet')));

        return ! ($preference === 'photo' && $this->hasUsablePhoto($main));
    }

    /**
     * How many reference images can actually travel with one request, so
     * prompts only describe characters whose reference cannot be sent.
     * The flow gateway carries exactly one and Grok Imagine three; other
     * providers follow the configurable cap (0 = unlimited).
     */
    private function referenceBudget(): int
    {
        $provider = (string) config('cubfable.ai.image_provider');

        if ($provider === 'flow') {
            return 1;
        }

        $cap = (int) config('cubfable.ai.max_image_references', 0);

        if ($provider === 'grok') {
            return $cap > 0 ? min($cap, 3) : 3;
        }

        return $cap;
    }

    /**
     * The stored hero sheet as an image reference, when it exists on disk.
     * Older books generated before anchoring simply return null and keep
     * their original behavior.
     */
    private function anchorFor(Book $book): ?ImageReference
    {
        $path = $book->hero_sheet_path;

        if ($path === null || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return new ImageReference($path, "{$book->child_name} (character sheet)");
    }

    /**
     * Build the per-page prompt + reference photos for the characters in a
     * scene. Art-directed pages get a film-brief layout (shot, setting from
     * the book bible, lighting from the color script, a find-it motif);
     * legacy pages keep the original single-sentence format.
     *
     * @param  Collection<int, Character>  $cast
     * @return array{prompt: string, references: list<ImageReference>}
     */
    private function buildScene(Book $book, Page $page, Collection $cast, Character $main, ?ImageReference $anchor = null): array
    {
        $artStyle = self::ART_STYLE_PROMPTS[$book->art_style] ?? self::ART_STYLE_PROMPTS['storybook'];
        $pageNumberLabel = "page {$page->page_number}";
        $sceneText = ($page->scene ?? '') !== '' ? (string) $page->scene : $page->text;
        $matchText = ($page->scene ?? '').' '.$page->text;

        $direction = $page->art_direction ?? [];
        $bible = $book->story_bible ?? [];
        $expression = $direction['expression'] ?? null;

        // The main character is always present; others only when named in the scene.
        $present = $cast->filter(
            fn (Character $character): bool => $character->id === $main->id || $this->nameInText($character->name, $matchText),
        );

        // Reference OR description, never both: a character whose reference
        // image actually travels with the request is identified by that image
        // alone (a text description would only fight it). Descriptions remain
        // for characters whose reference cannot be sent.
        $budget = $this->referenceBudget();
        $references = [];
        $anchorNote = '';

        if ($anchor !== null) {
            $references[] = $anchor;
            $anchorNote = "\nReference image 1 is the definitive character sheet for {$main->name}: copy their face, hairstyle, skin tone, outfit, colors and proportions EXACTLY as drawn there. It is already in the target art style; reproduce that exact rendition of {$main->name} in this scene.\n";
        }

        $lines = [];

        foreach ($present as $member) {
            $anchorCoversMember = $member->id === $main->id && $anchor !== null;

            $withinBudget = ! $anchorCoversMember
                && $this->hasUsablePhoto($member)
                && ($budget === 0 || count($references) < $budget);

            if ($withinBudget) {
                $references[] = new ImageReference((string) $member->photo_path, $member->name);
            }

            $roleText = $member->id === $main->id
                ? 'the main character/hero'
                : ($member->role !== null && $member->role !== '' ? $member->role : 'character');

            $expressionNote = $member->id === $main->id && $expression !== null
                ? " Expression: {$expression}."
                : '';

            if ($withinBudget) {
                $position = count($references);
                $lines[] = "- {$member->name}, {$roleText}: shown in attached reference image {$position} - copy their face, hair, build and outfit exactly from it, redrawn in the art style (an illustration, never a photo).{$expressionNote}";
            } elseif ($anchorCoversMember) {
                $lines[] = "- {$member->name}, {$roleText}: shown in attached reference image 1 (the character sheet).{$expressionNote}";
            } else {
                $appearance = trim((string) $member->appearance);
                $lines[] = "- {$member->name}, {$roleText}: ".($appearance !== '' ? $appearance : '(invent a consistent look)').$expressionNote;
            }
        }

        $characterLines = implode("\n", $lines);
        $sceneBlock = $this->sceneBlock($book, $page, $direction, $bible, $sceneText);

        $prompt = <<<PROMPT
{$artStyle}. Children's picture book illustration for {$pageNumberLabel}.

{$sceneBlock}

Characters in this scene (draw each EXACTLY and consistently):
{$characterLines}
{$anchorNote}
CHARACTER CONSISTENCY IS CRITICAL: keep every character's face, hair, facial hair, skin tone, outfit and accessories identical in every scene. Never change a character's appearance between pages. Redraw any photo-referenced character in the {$book->art_style} art style (an illustration, never a photo).

MOOD IS CRITICAL: {$main->name}'s expression must match the scene while staying positive - joyful when the moment is happy; curious, calm, focused, amazed or gently brave when it is quiet, mysterious or challenging. NEVER draw {$main->name} or any child looking sad, crying, scared, angry or distressed, and do NOT force a big smile where it does not fit the moment.

Style: warm, magical, safe for children, 16:9 landscape format, detailed background showing the {$book->theme} setting. No text or letters in the image. High quality illustration.
PROMPT;

        return ['prompt' => $prompt, 'references' => $references];
    }

    /**
     * The scene header: a film-brief block when art direction exists, the
     * legacy single-sentence format otherwise.
     *
     * @param  array<string, string>  $direction
     * @param  array<string, mixed>  $bible
     */
    private function sceneBlock(Book $book, Page $page, array $direction, array $bible, string $sceneText): string
    {
        if ($direction === []) {
            $subjectNote = $book->subject !== ''
                ? "\nThe story is about {$book->subject} - reflect it in the setting, props and action wherever it fits naturally."
                : '';

            return "Scene: {$sceneText}{$subjectNote}";
        }

        $shot = $direction['shot'] ?? 'medium';
        $action = $direction['action'] ?? $sceneText;

        $lines = ["SHOT: {$shot}: {$action}"];

        $world = trim($this->stringify($bible['world'] ?? null));
        $colorScript = is_array($bible['colorScript'] ?? null) ? $bible['colorScript'] : [];
        $lighting = trim($this->stringify($colorScript[$page->page_number - 1] ?? null));

        $setting = trim(($world !== '' ? $world.' ' : '').($lighting !== '' ? "Lighting: {$lighting}." : ''));

        if ($setting !== '') {
            $lines[] = "SETTING: {$setting}";
        }

        if (($direction['detail'] ?? '') !== '') {
            $lines[] = "DETAIL: {$direction['detail']}";
        }

        $motif = trim($this->stringify($bible['motif'] ?? null));

        if ($motif !== '') {
            $lines[] = "FIND-IT MOTIF: hide {$motif} somewhere subtle in the scene for the child to discover.";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Character>  $cast
     */
    private function generatePageImage(Page $page, Book $book, Collection $cast, Character $main, ?ImageReference $anchor = null): GeneratedImage
    {
        ['prompt' => $prompt, 'references' => $references] = $this->buildScene($book, $page, $cast, $main, $anchor);

        return $this->safeImages->generate($prompt, '1536x1024', $references, "page {$page->page_number}", new PromptLogContext($book->id, 'page', $page->id));
    }

    /**
     * Generate the cover image WITH its title rendered in the artwork. The
     * decorative gold frame is added by the UI (BookCover), so the prompt asks
     * for a clean margin and no border.
     */
    private function generateCoverImage(Book $book, Character $main, ?ImageReference $anchor = null): GeneratedImage
    {
        $artStyle = self::ART_STYLE_PROMPTS[$book->art_style] ?? self::ART_STYLE_PROMPTS['storybook'];
        $bible = $book->story_bible ?? [];
        $bibleSubtitle = $this->bibleLine($bible['subtitle'] ?? null, 60);
        $coverSubtitle = $bibleSubtitle ?? (self::COVER_SUBTITLES[$book->theme] ?? 'A Magical Adventure');
        $titleStyle = $this->bibleLine($bible['cover']['titleStyle'] ?? null, 300)
            ?? 'large, flowing, golden hand-lettered script';
        $coverMoment = $this->bibleLine($bible['cover']['moment'] ?? null, 600);
        $motif = $this->bibleLine($bible['motif'] ?? null, 200);

        // Reference OR description, never both (see buildScene).
        $coverReferences = [];
        $photoNote = '';
        $identityClause = '';

        if ($anchor !== null) {
            $coverReferences[] = $anchor;
            $identityClause = " The attached reference image is the definitive character sheet for {$main->name}: copy their face, hairstyle, skin tone, outfit and colors exactly as drawn there.";
        } elseif ($this->hasUsablePhoto($main)) {
            $coverReferences[] = new ImageReference((string) $main->photo_path, $main->name);
            $photoNote = ', the child shown in the attached reference photo (keep their face, hair and outfit clearly recognizable but redrawn in the art style, never a photograph)';
        } else {
            $appearance = trim((string) $main->appearance);
            $identityClause = $appearance !== '' ? " {$main->name}'s appearance: {$appearance}." : '';
        }

        $subjectClause = $book->subject !== ''
            ? ", with the story's subject ({$book->subject}) clearly featured in the scene"
            : '';

        $keyArt = $coverMoment !== null
            ? "COVER KEY ART (below the title): {$coverMoment} {$main->name}{$photoNote} is the focal point, mid-moment and full of joyful wonder.{$identityClause}"
            : "Below the title, {$main->name}{$photoNote} as the central hero, beaming with a big joyful smile, in a {$book->theme} setting{$subjectClause}.{$identityClause}";

        $motifLine = $motif !== null
            ? "\nHide {$motif} somewhere subtle in the artwork."
            : '';

        $coverPrompt = <<<PROMPT
A professionally illustrated children's storybook FRONT COVER, portrait orientation, in the {$book->art_style} art style ({$artStyle}). Compose it like a bestselling published picture-book cover: clear focal hierarchy (title first, then {$main->name}, then the world), gentle negative space around the title, and real depth with foreground and background layers.

TITLE TEXT (render it directly in the artwork at the top, beautifully and spelled EXACTLY, in English, clearly legible - this is the ONLY text anywhere on the cover):
  - First line: "{$main->name}" as {$titleStyle}.
  - Second line: "{$coverSubtitle}" in a smaller elegant classic serif.

{$keyArt} {$main->name} must look radiantly happy - never sad, scared or upset.{$motifLine}

Leave a small clean margin around the edges (a decorative frame is added separately - do NOT draw a border yourself). Warm, magical, richly detailed, with the title clearly readable at the top. Spell the title exactly as written; do not add any other words or letters.
PROMPT;

        return $this->safeImages->generate($coverPrompt, '1024x1536', $coverReferences, 'cover', new PromptLogContext($book->id, 'cover'));
    }

    /**
     * Generate and store the cover (1024x1536 portrait), replacing any
     * previous file.
     */
    private function storeCover(Book $book, Character $main, ?ImageReference $anchor = null): void
    {
        $image = $this->generateCoverImage($book, $main, $anchor);
        $path = sprintf('books/%d/cover-%s.png', $book->id, Str::lower(Str::random(8)));

        $this->images->storeGenerated($image->bytes, $path);

        $previous = $book->cover_image_path;
        $book->update(['cover_image_path' => $path, 'cover_prompt' => $image->prompt]);

        if ($previous !== $path) {
            $this->images->delete($previous);
        }
    }

    /**
     * Generate and store one page illustration (1536x1024 landscape),
     * replacing any previous file, and mark the page complete.
     *
     * @param  Collection<int, Character>  $cast
     */
    private function storePageIllustration(Page $page, Book $book, Collection $cast, Character $main, ?ImageReference $anchor = null): void
    {
        $image = $this->generatePageImage($page, $book, $cast, $main, $anchor);
        $path = sprintf('books/%d/pages/%d-%s.png', $book->id, $page->page_number, Str::lower(Str::random(8)));

        $this->images->storeGenerated($image->bytes, $path);

        $previous = $page->image_path;
        $page->update(['image_path' => $path, 'image_prompt' => $image->prompt, 'status' => PageStatus::Complete]);

        if ($previous !== $path) {
            $this->images->delete($previous);
        }
    }

    /**
     * A book's full cast (joined characters with pivot data), main character
     * first thanks to the relation's pivot ordering.
     *
     * @return Collection<int, Character>
     */
    private function castFor(Book $book): Collection
    {
        return $book->characters()->get();
    }

    /**
     * @param  Collection<int, Character>  $cast
     */
    private function mainCharacter(Collection $cast): ?Character
    {
        return $cast->first(fn (Character $character): bool => $this->isMainMember($character)) ?? $cast->first();
    }

    private function isMainMember(Character $character): bool
    {
        $pivot = $character->relationLoaded('pivot') ? $character->getRelation('pivot') : null;

        return $pivot instanceof Pivot && (bool) $pivot->getAttribute('is_main');
    }

    /**
     * True when a stored photo is a usable reference image on the public disk.
     */
    private function hasUsablePhoto(Character $character): bool
    {
        return $character->photo_path !== null
            && $character->photo_path !== ''
            && Storage::disk('public')->exists($character->photo_path);
    }

    /**
     * Script-aware word-boundary check. `\b` is ASCII-only and never fires
     * around non-Latin names (Arabic, CJK, Cyrillic...), which would silently
     * drop those characters from every scene. Use Unicode letter/number
     * lookarounds instead.
     */
    private function nameInText(string $name, string $text): bool
    {
        $name = trim($name);

        if ($name === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu';

        try {
            $result = preg_match($pattern, $text);
        } catch (Throwable) {
            $result = false;
        }

        if ($result === false) {
            return mb_stripos($text, $name) !== false;
        }

        return $result === 1;
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
