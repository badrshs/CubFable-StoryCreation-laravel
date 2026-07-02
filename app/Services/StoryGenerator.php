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
use App\Services\AI\ImageReference;
use App\Services\AI\SafeImageGenerator;
use App\Services\AI\UsageCollector;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
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
        // Legacy styles kept so older books still render.
        'cartoon' => 'vibrant cartoon illustration, bold outlines, bright cheerful colors, animated movie style',
        'storybook' => 'classic storybook illustration, detailed oil painting, warm golden light, fairy tale style',
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

            // Story text (localized display text + English scene per page)
            $story = $this->generateStoryText($book, $pageCount, $cast, $main);

            $pages = [];

            foreach ($story as $index => $storyPage) {
                $pages[] = Page::query()->create([
                    'book_id' => $book->id,
                    'page_number' => $index + 1,
                    'text' => $storyPage['text'],
                    'scene' => $storyPage['scene'],
                    'image_path' => null,
                    'status' => PageStatus::Generating,
                ]);
            }

            // Cover (generated WITH the title; the decorative frame is added in the UI)
            $this->storeCover($book, $main);

            // Pages (sequential to stay within rate limits)
            foreach ($pages as $page) {
                try {
                    $this->storePageIllustration($page, $book, $cast, $main);
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

            $this->storePageIllustration($page, $book, $cast, $main);
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

            $this->storeCover($book, $main);
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
     * @param  Collection<int, Character>  $cast
     * @return list<array{text: string, scene: string}>
     */
    private function generateStoryText(Book $book, int $pageCount, Collection $cast, Character $main): array
    {
        $langName = StoryLanguage::tryFrom($book->language)?->label() ?? 'English';

        $others = $cast
            ->reject(fn (Character $character): bool => $character->id === $main->id)
            ->map(fn (Character $character): string => $character->name.($character->role !== null && $character->role !== '' ? " ({$character->role})" : ''))
            ->implode(', ');
        $othersText = $others !== '' ? $others : 'none';

        $prompt = <<<PROMPT
You are a children's book author. Write a short illustrated storybook for a child named {$main->name} (age {$book->age_range}).

Story details:
- Setting / world: {$book->theme}
- What the story is about (the subject - make this central to the plot): {$book->subject}
- Life lesson: {$book->life_lesson}
- Art style: {$book->art_style}
- Additional characters: {$othersText}
- Story language: {$langName}

Write exactly {$pageCount} pages. {$main->name} is the hero; refer to other characters by name when they appear. For EACH page provide an object with:
  - "text": 2-3 short sentences of the story written in {$langName} (this is what the child reads).
  - "scene": a single vivid sentence in ENGLISH describing what is visually happening on that page (for the illustrator). ALWAYS write "scene" in English regardless of the story language. Mention by name which characters are present.

Return ONLY a JSON array of {$pageCount} objects: [{"text":"...","scene":"..."}]. No other text.
PROMPT;

        $content = $this->ai->generateText($prompt);

        if ($content === '') {
            $content = '[]';
        }

        if (preg_match('/\[[\s\S]*\]/', $content, $matches) !== 1) {
            throw new RuntimeException('Invalid story text response');
        }

        $parsed = json_decode($matches[0], true);

        if (! is_array($parsed)) {
            throw new RuntimeException('Invalid story text response');
        }

        $pages = [];

        foreach (array_slice(array_values($parsed), 0, $pageCount) as $item) {
            if (is_string($item)) {
                $pages[] = ['text' => $item, 'scene' => $item];

                continue;
            }

            $rawText = is_array($item) ? ($item['text'] ?? null) : null;
            $rawScene = is_array($item) ? ($item['scene'] ?? null) : null;

            $pages[] = [
                'text' => $this->stringify($rawText),
                'scene' => $this->stringify($rawScene ?? $rawText),
            ];
        }

        return $pages;
    }

    /**
     * Build the per-page prompt + reference photos for the characters in a scene.
     *
     * @param  Collection<int, Character>  $cast
     * @return array{prompt: string, references: list<ImageReference>}
     */
    private function buildScene(Book $book, string $pageNumberLabel, string $sceneText, string $matchText, Collection $cast, Character $main): array
    {
        $artStyle = self::ART_STYLE_PROMPTS[$book->art_style] ?? self::ART_STYLE_PROMPTS['storybook'];

        // The main character is always present; others only when named in the scene.
        $present = $cast->filter(
            fn (Character $character): bool => $character->id === $main->id || $this->nameInText($character->name, $matchText),
        );

        $references = [];
        $lines = [];

        foreach ($present as $member) {
            $hasPhoto = $this->hasUsablePhoto($member);

            if ($hasPhoto) {
                $references[] = new ImageReference((string) $member->photo_path, $member->name);
            }

            $roleText = $member->id === $main->id
                ? 'the main character/hero'
                : ($member->role !== null && $member->role !== '' ? $member->role : 'character');
            $photoNote = $hasPhoto
                ? ' (a reference photo of this character is provided - match their face, hair and build, redrawn in the art style, never a photo)'
                : '';
            $appearance = trim((string) $member->appearance);

            $lines[] = "- {$member->name}, {$roleText}{$photoNote}: ".($appearance !== '' ? $appearance : '(invent a consistent look)');
        }

        $subjectNote = $book->subject !== ''
            ? "\nThe story is about {$book->subject} - reflect it in the setting, props and action wherever it fits naturally."
            : '';

        $characterLines = implode("\n", $lines);

        $prompt = <<<PROMPT
{$artStyle}. Children's picture book illustration for {$pageNumberLabel}.

Scene: {$sceneText}{$subjectNote}

Characters in this scene (draw each EXACTLY and consistently):
{$characterLines}

CHARACTER CONSISTENCY IS CRITICAL: keep every character's face, hair, facial hair, skin tone, outfit and accessories identical in every scene. Never change a character's appearance between pages. Redraw any photo-referenced character in the {$book->art_style} art style (an illustration, never a photo).

Style: warm, magical, safe for children, 16:9 landscape format, detailed background showing the {$book->theme} setting. No text or letters in the image. High quality illustration.
PROMPT;

        return ['prompt' => $prompt, 'references' => $references];
    }

    /**
     * @param  Collection<int, Character>  $cast
     */
    private function generatePageImage(string $sceneText, string $matchText, int $pageNumber, Book $book, Collection $cast, Character $main): string
    {
        ['prompt' => $prompt, 'references' => $references] = $this->buildScene($book, "page {$pageNumber}", $sceneText, $matchText, $cast, $main);

        return $this->safeImages->generate($prompt, '1536x1024', $references, "page {$pageNumber}");
    }

    /**
     * Generate the cover image WITH its title rendered in the artwork. The
     * decorative gold frame is added by the UI (BookCover), so the prompt asks
     * for a clean margin and no border.
     */
    private function generateCoverImage(Book $book, Character $main): string
    {
        $artStyle = self::ART_STYLE_PROMPTS[$book->art_style] ?? self::ART_STYLE_PROMPTS['storybook'];
        $usePhotoCover = $this->hasUsablePhoto($main);
        $coverReferences = $usePhotoCover ? [new ImageReference((string) $main->photo_path, $main->name)] : [];
        $coverSubtitle = self::COVER_SUBTITLES[$book->theme] ?? 'A Magical Adventure';

        $photoNote = $usePhotoCover
            ? ', the child shown in the reference photo (keep their face, hair and outfit clearly recognizable but redrawn in the art style, never a photograph)'
            : '';
        $subjectClause = $book->subject !== ''
            ? ", with the story's subject ({$book->subject}) clearly featured in the scene"
            : '';
        $appearance = trim((string) $main->appearance);
        $appearanceClause = $appearance !== '' ? " {$main->name}'s appearance: {$appearance}." : '';

        $coverPrompt = <<<PROMPT
A professionally illustrated children's storybook FRONT COVER, portrait orientation, in the {$book->art_style} art style ({$artStyle}). Make it look like a real published picture book.

TITLE TEXT (render it directly in the artwork at the top, beautifully and spelled EXACTLY, in English, clearly legible - this is the ONLY text anywhere on the cover):
  - First line: "{$main->name}" as large, flowing, golden hand-lettered script.
  - Second line: "{$coverSubtitle}" in a smaller elegant classic serif.

Below the title, {$main->name}{$photoNote} as the central hero, in a {$book->theme} setting{$subjectClause}.{$appearanceClause}

Leave a small clean margin around the edges (a decorative frame is added separately - do NOT draw a border yourself). Warm, magical, richly detailed, with the title clearly readable at the top. Spell the title exactly as written; do not add any other words or letters.
PROMPT;

        return $this->safeImages->generate($coverPrompt, '1024x1536', $coverReferences, 'cover');
    }

    /**
     * Generate and store the cover (1024x1536 portrait), replacing any
     * previous file.
     */
    private function storeCover(Book $book, Character $main): void
    {
        $bytes = $this->generateCoverImage($book, $main);
        $path = sprintf('books/%d/cover-%s.png', $book->id, Str::lower(Str::random(8)));

        $this->images->storeGenerated($bytes, $path);

        $previous = $book->cover_image_path;
        $book->update(['cover_image_path' => $path]);

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
    private function storePageIllustration(Page $page, Book $book, Collection $cast, Character $main): void
    {
        $sceneText = ($page->scene ?? '') !== '' ? (string) $page->scene : $page->text;
        $matchText = ($page->scene ?? '').' '.$page->text;

        $bytes = $this->generatePageImage($sceneText, $matchText, $page->page_number, $book, $cast, $main);
        $path = sprintf('books/%d/pages/%d-%s.png', $book->id, $page->page_number, Str::lower(Str::random(8)));

        $this->images->storeGenerated($bytes, $path);

        $previous = $page->image_path;
        $page->update(['image_path' => $path, 'status' => PageStatus::Complete]);

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
