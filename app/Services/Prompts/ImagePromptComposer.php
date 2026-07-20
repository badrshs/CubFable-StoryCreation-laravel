<?php

namespace App\Services\Prompts;

use App\Models\Book;
use App\Models\Character;
use App\Models\Page;
use App\Services\AI\ImageReference;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Composes every image prompt (character sheet, cover, page illustration)
 * plus the reference images that travel with it.
 */
class ImagePromptComposer
{
    /**
     * English-only cover subtitles, keyed by theme. The cover title is always
     * English regardless of the story language. Legacy fallback: only fires
     * when the book bible has no subtitle.
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
        private ArtStyleLibrary $styles,
        private ReferencePolicy $policy,
        private IdentityCapsule $identity,
    ) {}

    /**
     * The art style to draw in. A per-run override (set by an admin testing a
     * single image in a different style) wins over the book's stored style,
     * for this generation only - the book itself is never changed.
     */
    private function styleKey(Book $book): string
    {
        $override = (string) config('cubfable.ai.style_override', '');

        return $override !== '' ? $override : $book->art_style;
    }

    /**
     * The character-sheet prompt and references: one canonical stylized
     * rendition of the hero that anchors the cover and every page.
     *
     * @return array{prompt: string, references: list<ImageReference>}
     */
    public function sheet(Book $book, Character $main): array
    {
        $artStyle = $this->styles->descriptor($this->styleKey($book));
        $usePhoto = $this->policy->hasUsablePhoto($main);
        $references = $usePhoto ? [new ImageReference((string) $main->photo_path, $main->name)] : [];

        // The photo IS the identity when it travels; a text description
        // exists only when it cannot.
        $appearance = trim((string) $main->appearance);
        $identity = $usePhoto
            ? $this->identity->referenceLine($main->name, 1, null, $main->age_group)
            : ($appearance !== '' ? $this->identity->descriptionLine($main->name, $main->appearance, null, $main->age_group) : '');

        $constraints = $this->constraintsBlock($this->styleKey($book), $references !== [], [
            "Only {$main->name} in the frame, large and clear.",
            'No text, letters, numbers, watermarks or logos in the image.',
        ]);

        $prompt = <<<PROMPT
STYLE: {$artStyle}.

Character sheet for a children's picture book: one full-body illustration of {$main->name}, standing and facing the viewer with a friendly smile, on a plain soft background.

{$identity}

{$constraints}
PROMPT;

        return ['prompt' => $prompt, 'references' => $references];
    }

    /**
     * The cover prompt and references, with the title rendered in the
     * artwork. Full-bleed flat artwork only: any mention of margins or
     * borders makes engines render a physical book mockup on a background,
     * and the decorative frame is added by the UI anyway.
     *
     * @return array{prompt: string, references: list<ImageReference>}
     */
    public function cover(Book $book, Character $main, ?ImageReference $anchor = null): array
    {
        $artStyle = $this->styles->descriptor($this->styleKey($book));
        $bible = $book->story_bible ?? [];
        $bibleSubtitle = PromptText::line($bible['subtitle'] ?? null, 60);
        $coverSubtitle = $bibleSubtitle ?? (self::COVER_SUBTITLES[$book->theme] ?? 'A Magical Adventure');
        $titleStyle = PromptText::line($bible['cover']['titleStyle'] ?? null, 300)
            ?? 'large, flowing, golden hand-lettered script';
        $coverMoment = PromptText::line($bible['cover']['moment'] ?? null, 600);
        $motif = PromptText::line($bible['motif'] ?? null, 200);
        $motif = $motif !== null ? rtrim($motif, '.') : null;

        // Reference-as-authority plus capsule (see page()). An engine with
        // no reference support gets the full text description instead.
        $coverReferences = [];

        if ($this->policy->budget() !== 0) {
            if ($anchor !== null) {
                $coverReferences[] = $anchor;
            } elseif ($this->policy->hasUsablePhoto($main)) {
                $coverReferences[] = new ImageReference((string) $main->photo_path, $main->name);
            }
        }

        $appearance = trim((string) $main->appearance);
        $identity = $coverReferences !== []
            ? $this->identity->referenceLine($main->name, 1, null, $main->age_group)
            : ($appearance !== '' ? $this->identity->descriptionLine($main->name, $main->appearance, null, $main->age_group) : '');

        $subjectClause = $book->subject !== ''
            ? " featuring {$book->subject}"
            : '';

        $keyArt = $coverMoment !== null
            ? "Key art below the title: {$coverMoment}"
            : "Key art below the title: {$main->name} happy in a {$book->theme} setting{$subjectClause}.";

        $motifLine = $motif !== null
            ? "\nHide {$motif} somewhere in the artwork."
            : '';

        $constraints = $this->constraintsBlock($this->styleKey($book), $coverReferences !== [], [
            'The artwork fills the whole canvas edge to edge: no borders, no frames, no margins.',
            'Flat cover artwork only, never a photo or mockup of a physical book: no spine, no page edges, no shadow or surface behind the cover.',
            'No words or letters in the artwork beyond the two title lines above.',
        ]);

        $coverPrompt = <<<PROMPT
STYLE: {$artStyle}.

Front cover artwork for a children's picture book, {$this->orientationPhrase()}.

Title, lettered into the artwork at the top and spelled exactly:
- "{$main->name}" - {$titleStyle}
- "{$coverSubtitle}" - smaller, classic serif

{$keyArt}
{$identity}{$motifLine}

{$constraints}
PROMPT;

        return ['prompt' => $coverPrompt, 'references' => $coverReferences];
    }

    /**
     * The per-page prompt + reference photos for the characters in a scene.
     * Art-directed pages get a film-brief layout (shot, setting from the
     * book bible, lighting from the color script, a find-it motif); legacy
     * pages keep the original single-sentence format.
     *
     * @param  Collection<int, Character>  $cast
     * @return array{prompt: string, references: list<ImageReference>}
     */
    public function page(Book $book, Page $page, Collection $cast, Character $main, ?ImageReference $anchor = null): array
    {
        $artStyle = $this->styles->descriptor($this->styleKey($book));
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

        // A character whose reference image travels is named against that
        // image (the authority) plus a short hair-and-outfit capsule that
        // persists even when the engine's session memory fades; the full
        // text description is reserved for characters whose reference
        // cannot be sent.
        $budget = $this->policy->budget();
        $references = [];

        if ($anchor !== null && $budget !== 0) {
            $references[] = $anchor;
        }

        $lines = [];

        foreach ($present as $member) {
            $anchorCoversMember = $member->id === $main->id && $references !== [] && $anchor !== null;

            $withinBudget = ! $anchorCoversMember
                && $this->policy->hasUsablePhoto($member)
                && ($budget === null || count($references) < $budget);

            if ($withinBudget) {
                $references[] = new ImageReference((string) $member->photo_path, $member->name);
            }

            $memberExpression = $member->id === $main->id ? $expression : null;

            if ($withinBudget) {
                $lines[] = '- '.$this->identity->referenceLine($member->name, count($references), $memberExpression, $member->age_group);
            } elseif ($anchorCoversMember) {
                $lines[] = '- '.$this->identity->referenceLine($member->name, 1, $memberExpression, $member->age_group);
            } else {
                $lines[] = '- '.$this->identity->descriptionLine($member->name, $member->appearance, $memberExpression, $member->age_group);
            }
        }

        $characterLines = implode("\n", $lines);
        $sceneBlock = $this->sceneBlock($book, $page, $direction, $bible, $sceneText);

        $constraints = $this->constraintsBlock($this->styleKey($book), $references !== [], [
            'No text, letters, numbers, watermarks or logos in the image.',
            'Every character is part of the scene: mid-action, interacting with the setting, never stiffly posed like a photograph.',
        ]);

        $prompt = <<<PROMPT
STYLE: {$artStyle}.

Children's picture book illustration for {$pageNumberLabel}, {$this->orientationPhrase()}, warm and magical, with a detailed {$book->theme} background.

{$sceneBlock}

Characters:
{$characterLines}

{$constraints}
PROMPT;

        return ['prompt' => $prompt, 'references' => $references];
    }

    /**
     * The shared closing block of every image prompt: the hard rules as
     * bullets (target-specific first, then the style's anti-drift hints,
     * then - only when a reference actually travels - how to translate the
     * referenced person into the style), closed by the style reinforcement
     * line. Kept calm and factual on purpose: pressure language earned
     * nothing here historically.
     *
     * @param  list<string>  $targetRules
     */
    private function constraintsBlock(string $style, bool $withReferences, array $targetRules): string
    {
        $bullets = [...$targetRules, ...$this->styles->antiDriftHints($style)];

        if ($withReferences) {
            // Reference photos pull engines toward pasting a photographic
            // person into an illustrated scene; both lines push back.
            $bullets[] = 'No photographic faces or photo textures anywhere: every person is fully repainted in the illustration style.';
            $bullets[] = $this->styles->referenceAdaptationLine($style);
        }

        $list = '- '.implode("\n- ", $bullets);

        return "STRICT CONSTRAINTS:\n{$list}\n{$this->styles->reinforcementLine($style)}";
    }

    /**
     * One prompt describing a set of pages as numbered scenes, for engines
     * that render a whole set coherently (Seedream sequential generation).
     * The shared blocks (style, world, characters, constraints) appear once;
     * each scene is one compact line, so the set stays inside Seedream's
     * 4000-character prompt budget. When a book renders in several batches,
     * $styleAnchor carries a finished page from an earlier batch so later
     * batches match its look exactly.
     *
     * @param  list<Page>  $pages
     * @param  Collection<int, Character>  $cast
     * @return array{prompt: string, references: list<ImageReference>}
     */
    public function pageGroup(Book $book, array $pages, Collection $cast, Character $main, ?ImageReference $anchor = null, ?ImageReference $styleAnchor = null): array
    {
        $artStyle = $this->styles->descriptor($this->styleKey($book));
        $bible = $book->story_bible ?? [];
        $count = count($pages);

        // Presence and references are computed across the WHOLE book: a
        // character named on any page travels once and is named once.
        $matchText = implode(' ', array_map(fn (Page $page): string => ($page->scene ?? '').' '.$page->text, $pages));
        $present = $cast->filter(
            fn (Character $character): bool => $character->id === $main->id || $this->nameInText($character->name, $matchText),
        );

        $budget = $this->policy->budget();
        $references = [];

        if ($anchor !== null && $budget !== 0) {
            $references[] = $anchor;
        }

        $lines = [];

        foreach ($present as $member) {
            $anchorCoversMember = $member->id === $main->id && $references !== [] && $anchor !== null;

            $withinBudget = ! $anchorCoversMember
                && $this->policy->hasUsablePhoto($member)
                && ($budget === null || count($references) < $budget);

            if ($withinBudget) {
                $references[] = new ImageReference((string) $member->photo_path, $member->name);
                $lines[] = '- '.$this->identity->referenceLine($member->name, count($references), null, $member->age_group);
            } elseif ($anchorCoversMember) {
                $lines[] = '- '.$this->identity->referenceLine($member->name, 1, null, $member->age_group);
            } else {
                $lines[] = '- '.$this->identity->descriptionLine($member->name, $member->appearance, null, $member->age_group);
            }
        }

        $characterLines = implode("\n", $lines);

        $world = PromptText::clip(PromptText::stringify($bible['world'] ?? null), 300);
        $worldLine = $world !== '' ? "SETTING (all scenes): {$world}\n\n" : '';

        $motif = rtrim(trim(PromptText::stringify($bible['motif'] ?? null)), '.');
        $motifLine = $motif !== '' ? "\nFIND-IT MOTIF: hide {$motif} somewhere subtle in every scene.\n" : '';

        $targetRules = [
            "Exactly {$count} images, strictly in order: the first image shows SCENE 1, the second shows SCENE 2, and so on - one image per scene, never combined, never skipped, never reordered.",
            'The same characters, world and style in every image, like pages of one book.',
            'No text, letters, numbers, watermarks or logos in any image.',
            'Every character is part of the scene: mid-action, interacting with the setting, never stiffly posed like a photograph.',
        ];

        // A finished page from an earlier batch of this same book: later
        // batches copy its look so the whole book reads as one set.
        if ($styleAnchor !== null) {
            $references[] = $styleAnchor;
            $targetRules[] = 'Reference image '.count($references).' is a finished page of this same book: match its style, palette and rendering exactly.';
        }

        $constraints = $this->constraintsBlock($this->styleKey($book), $references !== [], $targetRules);

        // Engines cap the prompt (Seedream: 4000 chars). Verbose author
        // scenes are clipped progressively tighter until the set fits;
        // callers fall back to page-by-page only when even the tightest
        // form cannot fit.
        $prompt = '';

        foreach ([280, 210, 150, 110] as $actionCap) {
            // Scenes are numbered 1..N within THIS request (the code keeps
            // the true page mapping): a batch labeled "SCENE 5..7" made the
            // model mis-map outputs; 1..N is unambiguous.
            $scenes = implode("\n", array_map(
                fn (Page $page, int $index): string => $this->groupSceneLine($page, $index + 1, $main, $bible, $actionCap),
                $pages,
                array_keys(array_values($pages)),
            ));

            $prompt = <<<PROMPT
STYLE: {$artStyle}.

Illustrate {$count} scenes of one children's picture book as {$count} separate images, {$this->orientationPhrase()}, warm and magical, with a detailed {$book->theme} background.

{$worldLine}{$scenes}
{$motifLine}
Characters (the same in every scene):
{$characterLines}

{$constraints}
PROMPT;

            if (mb_strlen($prompt) <= 3800) {
                break;
            }
        }

        return ['prompt' => $prompt, 'references' => $references];
    }

    /**
     * The orientation wording every book image prompt carries, derived from
     * the configured aspect ratio so the words never fight the requested
     * canvas (e.g. "9:16 portrait", "3:2 landscape", "square").
     */
    private function orientationPhrase(): string
    {
        $ratio = trim((string) config('cubfable.ai.image_aspect_ratio', '9:16'));

        if (preg_match('/^(\d+):(\d+)$/', $ratio, $matches) !== 1) {
            return '9:16 portrait';
        }

        return match (true) {
            (int) $matches[1] > (int) $matches[2] => "{$ratio} landscape",
            (int) $matches[1] < (int) $matches[2] => "{$ratio} portrait",
            default => 'square',
        };
    }

    /**
     * One compact numbered scene line for the group prompt, its action
     * clipped to the given budget. The scene number is the 1-based position
     * inside THIS request, not the page number.
     *
     * @param  array<string, mixed>  $bible
     */
    private function groupSceneLine(Page $page, int $sceneNumber, Character $main, array $bible, int $actionCap): string
    {
        $direction = $page->art_direction ?? [];
        $sceneText = ($page->scene ?? '') !== '' ? (string) $page->scene : $page->text;
        $shot = $direction['shot'] ?? 'medium';
        $action = PromptText::clip((string) ($direction['action'] ?? $sceneText), $actionCap);
        $expression = ($direction['expression'] ?? '') !== '' ? " {$main->name} looks {$direction['expression']}." : '';
        $detail = ($direction['detail'] ?? '') !== ''
            ? ' Detail: '.PromptText::clip((string) $direction['detail'], 80).'.'
            : '';
        $colorScript = is_array($bible['colorScript'] ?? null) ? $bible['colorScript'] : [];
        $lighting = rtrim(trim(PromptText::stringify($colorScript[$page->page_number - 1] ?? null)), '.');
        $lightingNote = $lighting !== '' ? " Lighting: {$lighting}." : '';

        return "SCENE {$sceneNumber}: {$shot}: {$action}.{$expression}{$detail}{$lightingNote}";
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

        $world = trim(PromptText::stringify($bible['world'] ?? null));
        $colorScript = is_array($bible['colorScript'] ?? null) ? $bible['colorScript'] : [];
        $lighting = rtrim(trim(PromptText::stringify($colorScript[$page->page_number - 1] ?? null)), '.');

        $setting = trim(($world !== '' ? $world.' ' : '').($lighting !== '' ? "Lighting: {$lighting}." : ''));

        if ($setting !== '') {
            $lines[] = "SETTING: {$setting}";
        }

        if (($direction['detail'] ?? '') !== '') {
            $lines[] = "DETAIL: {$direction['detail']}";
        }

        $motif = rtrim(trim(PromptText::stringify($bible['motif'] ?? null)), '.');

        if ($motif !== '') {
            $lines[] = "FIND-IT MOTIF: hide {$motif} somewhere subtle in the scene for the child to discover.";
        }

        return implode("\n", $lines);
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
}
