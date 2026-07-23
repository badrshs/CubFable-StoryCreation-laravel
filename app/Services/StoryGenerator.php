<?php

namespace App\Services;

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Exceptions\ImageFlaggedSensitiveException;
use App\Models\Book;
use App\Models\Character;
use App\Models\CharacterPortrait;
use App\Models\ImagePrompt;
use App\Models\ImageVersion;
use App\Models\Page;
use App\Models\Template;
use App\Services\AI\AiManager;
use App\Services\AI\AppearanceDescriber;
use App\Services\AI\FlowSessionContext;
use App\Services\AI\GeneratedImage;
use App\Services\AI\ImageReference;
use App\Services\AI\ImageSizePolicy;
use App\Services\AI\PromptLogContext;
use App\Services\AI\SafeImageGenerator;
use App\Services\AI\UsageCollector;
use App\Services\Prompts\IdentityCapsule;
use App\Services\Prompts\ImagePromptComposer;
use App\Services\Prompts\PromptText;
use App\Services\Prompts\ReferencePolicy;
use App\Services\Prompts\StoryPromptComposer;
use App\Support\MediaDisk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StoryGenerator
{
    public function __construct(
        private AiManager $ai,
        private SafeImageGenerator $safeImages,
        private AppearanceDescriber $appearanceDescriber,
        private BookImageStorage $images,
        private UsageCollector $usage,
        private FlowSessionContext $flowSession,
        private StoryPromptComposer $storyPrompts,
        private ImagePromptComposer $imagePrompts,
        private ReferencePolicy $references,
        private IdentityCapsule $identity,
        private ImageVersionArchiver $versions,
        private ImageSizePolicy $sizes,
        private BookStopSignal $stopSignal,
    ) {}

    /**
     * Halt the pipeline when the admin pressed Stop: no further image is
     * generated, the outer catch flips the book to Failed, and Resume picks
     * the run back up later with every finished image kept.
     */
    private function abortIfStopRequested(Book $book): void
    {
        if ($this->stopSignal->requested($book->id)) {
            throw new RuntimeException('Generation stopped by the admin.');
        }
    }

    /**
     * Key one book run to one gateway conversation, so a session-capable
     * browser engine keeps every image in one chat (no page reloads); the
     * gateway re-attaches the reference on every prompt, because a bare
     * follow-up in a Grok conversation implicitly references the LAST
     * generated image. Style is part of the key: a restyle starts a fresh
     * conversation.
     */
    private function startFlowSession(Book $book): void
    {
        $this->flowSession->key = "book-{$book->id}-{$book->art_style}";
    }

    /**
     * Run the full generation pipeline: story text, cover, then every page
     * illustration sequentially (to stay within rate limits).
     */
    public function generateStorybook(Book $book): void
    {
        try {
            // A stale stop from an earlier run must never kill this one:
            // starting a run is always an intentional act.
            $this->stopSignal->clear($book->id);

            $book->update(['status' => BookStatus::Generating]);
            $this->startFlowSession($book);

            $template = Template::query()->findOrFail($book->template_id);
            $pageCount = $template->page_count;

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
                $blueprint = $this->generateStoryBlueprint($book, $pageCount, $cast, $main, $template);
                $pages = $this->persistBlueprint($book, $blueprint);
                Log::info('Story blueprint written and persisted.', ['pages' => count($pages)]);
            }
            // Existing pages mean an interrupted run being resumed: the story
            // and bible are already persisted, only missing images remain.

            // One canonical stylized rendition of the hero. The cover and
            // every page copy it, so the photo-to-illustration jump happens
            // once here instead of independently on every image. In photo
            // mode the original upload leads instead and no sheet is made.
            // A sheet that survived an interrupted run is reused as-is.
            $this->abortIfStopRequested($book);
            $anchor = $this->references->anchorsWithSheet($main)
                ? ($this->anchorFor($book) ?? $this->prepareHeroSheet($book, $main))
                : null;

            // Every cast member follows the same rule as the hero: a
            // photographed supporting character is drawn once as a stylized
            // portrait (cached per character + style) and referenced by that
            // instead of the raw photo. Keyed by character id.
            $castPortraits = $this->castPortraits($book, $cast, $main, $anchor);

            // Cover (generated WITH the title; the decorative frame is added
            // in the UI). Skipped when a previous run already produced one.
            // A cover every engine refuses on content grounds is parked for
            // admin review instead of failing the whole paid book.
            if ($book->cover_image_path === null) {
                $this->abortIfStopRequested($book);

                try {
                    $this->storeCover($book, $main, $anchor);
                } catch (ImageFlaggedSensitiveException $exception) {
                    Log::error("Cover flagged as sensitive by every engine: {$exception->getMessage()}");
                    $book->update(['cover_flagged_at' => now()]);
                }
            }

            // A fresh book renders every page as ONE coherent set when the
            // engine supports it (characters and style stay consistent by
            // construction); any failure or shortfall falls through to the
            // page-by-page loop below.
            $pending = array_filter($pages, fn (Page $page): bool => ! ($page->status === PageStatus::Complete && $page->image_path !== null));

            if (count($pending) === count($pages) && $this->groupGenerationAvailable(count($pages))) {
                try {
                    $this->storePagesAsGroup($book, $pages, $cast, $main, $anchor, $castPortraits);
                } catch (Throwable $exception) {
                    Log::warning("Group generation failed; falling back to page-by-page: {$exception->getMessage()}");
                }
            }

            // Pages (sequential to stay within rate limits); pages that
            // already have their illustration are never re-billed.
            foreach ($pages as $page) {
                if ($page->status === PageStatus::Complete && $page->image_path !== null) {
                    continue;
                }

                // Outside the per-page try: a stop ends the whole run (the
                // book flips to Failed and stays resumable), it does not
                // just skip one page.
                $this->abortIfStopRequested($book);

                try {
                    $this->storePageIllustration($page, $book, $cast, $main, $anchor, $castPortraits);
                } catch (ImageFlaggedSensitiveException $exception) {
                    Log::error("Page {$page->page_number} flagged as sensitive by every engine: {$exception->getMessage()}");
                    $page->update(['status' => PageStatus::Failed, 'flagged_at' => now()]);
                } catch (Throwable $exception) {
                    Log::error("Failed to generate image for page {$page->page_number}: {$exception->getMessage()}");
                    $page->update(['status' => PageStatus::Failed]);
                }
            }

            $book->update(['status' => BookStatus::Complete]);
            Log::info('Book generation complete.');
        } catch (Throwable $exception) {
            Log::error("Storybook generation failed: {$exception->getMessage()}");

            // A restyle or resume may already have flipped this book back to
            // Pending and queued a new run; only the run that still owns the
            // book (status Generating) may mark it Failed, or the queued run
            // refuses to start and the book is stuck.
            Book::query()->whereKey($book->id)
                ->where('status', BookStatus::Generating)
                ->update(['status' => BookStatus::Failed]);

            // An honored stop has served its purpose. Left in place (1 hour
            // TTL) it would instantly kill the next intentional run.
            $this->stopSignal->clear($book->id);
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
            // Books that predate version tracking get their current image
            // captured before it is replaced, so it stays restorable.
            $this->versions->capturePage($book, $page);
            $this->startFlowSession($book);
            $cast = $this->castFor($book);
            $this->ensureCast($cast, $book->art_style);
            $main = $this->mainCharacter($cast);

            if ($main === null) {
                throw new RuntimeException('Book has no characters');
            }

            $anchor = $this->manualRegenAnchor($book, $main);
            $castPortraits = $this->cachedCastPortraits($book, $cast, $main, $anchor);
            $this->storePageIllustration($page, $book, $cast, $main, $anchor, $castPortraits);
        } catch (ImageFlaggedSensitiveException $exception) {
            // Park it for review. A page that already has a good image keeps
            // it (and its Complete status): a failed regeneration must never
            // destroy paid work.
            Log::error("Failed to regenerate page {$page->id}: flagged as sensitive by every engine: {$exception->getMessage()}");
            $page->update(['flagged_at' => now(), ...($page->image_path === null ? ['status' => PageStatus::Failed] : [])]);
        } catch (Throwable $exception) {
            Log::error("Failed to regenerate page {$page->id}: {$exception->getMessage()}");

            if ($page->image_path === null) {
                $page->update(['status' => PageStatus::Failed]);
            }
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
            // Books that predate version tracking get their current cover
            // captured before it is replaced, so it stays restorable.
            $this->versions->captureCover($book);
            $this->startFlowSession($book);
            $cast = $this->castFor($book);
            $this->ensureCast($cast, $book->art_style);
            $main = $this->mainCharacter($cast);

            if ($main === null) {
                throw new RuntimeException('Book has no characters');
            }

            $this->storeCover($book, $main, $this->manualRegenAnchor($book, $main));
            $book->update(['cover_status' => null]);
        } catch (ImageFlaggedSensitiveException $exception) {
            Log::error("Failed to regenerate cover for book {$book->id}: flagged as sensitive by every engine: {$exception->getMessage()}");
            $book->update(['cover_status' => 'failed', 'cover_flagged_at' => now()]);
            $failure = $exception;
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
                $appearance = $this->references->hasUsablePhoto($member)
                    ? trim($this->appearanceDescriber->describe((new ImageReference((string) $member->photo_path))->dataUrl()))
                    : trim($this->ai->generateText($this->identity->textDescriptionPrompt($member, $artStyle)));
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
     * Persist a parsed blueprint: the reusable bible before any image work (so
     * the cover subtitle and page regenerations reuse the same world and
     * lighting), then one Page row per story page. Returns the created pages.
     *
     * @param  array{subtitle: ?string, world: ?string, motif: ?string, refrain: ?string, colorScript: ?list<string>, cover: ?array<string, string>, pages: list<array{text: string, scene: string, artDirection: ?array<string, string>}>}  $blueprint
     * @return list<Page>
     */
    private function persistBlueprint(Book $book, array $blueprint): array
    {
        $bible = array_filter(Arr::except($blueprint, ['pages']), fn ($value): bool => $value !== null);
        $book->update(['story_bible' => $bible !== [] ? $bible : null]);

        $pages = [];

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

        return $pages;
    }

    /**
     * One call, two jobs: the author writes the localized story and the art
     * director plans how every page looks (a reusable world, a lighting arc,
     * per-page shots, a hidden motif, a bespoke cover subtitle).
     *
     * @param  Collection<int, Character>  $cast
     * @return array{subtitle: ?string, world: ?string, motif: ?string, refrain: ?string, colorScript: ?list<string>, cover: ?array<string, string>, pages: list<array{text: string, scene: string, artDirection: ?array<string, string>}>}
     */
    private function generateStoryBlueprint(Book $book, int $pageCount, Collection $cast, Character $main, ?Template $template = null): array
    {
        $content = $this->ai->generateText($this->storyPrompts->authorPrompt($book, $pageCount, $cast, $main, $template));

        return $this->parseBlueprint($content, $pageCount);
    }

    /**
     * Every prompt generation would compose for this book, with zero AI calls:
     * the author/art-director prompt, the character sheet (when the identity
     * anchors on one), the cover, and one page. Books without pages preview a
     * representative sample page. Powers the admin prompt playground.
     *
     * @param  Collection<int, Character>|null  $cast  explicit cast for unsaved sample books
     * @return array{blueprint: string, sheet: ?string, cover: string, page: string}
     */
    public function previewPrompts(Book $book, ?Collection $cast = null): array
    {
        $cast ??= $this->castFor($book);
        $main = $this->mainCharacter($cast);

        if ($main === null) {
            throw new RuntimeException('Book has no characters');
        }

        $template = Template::query()->find((int) $book->getAttribute('template_id'));
        $pageCount = $template !== null ? (int) $template->page_count : 6;

        $anchor = $this->references->anchorsWithSheet($main) ? $this->anchorFor($book) : null;

        $page = $book->exists ? $book->pages()->orderBy('page_number')->first() : null;
        $page ??= new Page([
            'page_number' => 1,
            'text' => "{$main->name} steps into the adventure.",
            'scene' => "{$main->name} discovers something wonderful at the edge of the {$book->theme}.",
            'art_direction' => [
                'shot' => 'wide establishing',
                'action' => "{$main->name} discovers something wonderful at the edge of the {$book->theme}.",
                'expression' => 'curious',
                'detail' => 'a tiny keepsake tucked in their pocket',
            ],
        ]);

        return [
            'blueprint' => $this->storyPrompts->authorPrompt($book, $pageCount, $cast, $main, $template),
            'sheet' => $this->references->anchorsWithSheet($main) ? $this->imagePrompts->sheet($book, $main)['prompt'] : null,
            'cover' => $this->imagePrompts->cover($book, $main, $anchor)['prompt'],
            'page' => $this->imagePrompts->page($book, $page, $cast, $main, $anchor)['prompt'],
        ];
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
                    $value = trim(PromptText::stringify($rawScene[$key] ?? null));

                    if ($value !== '') {
                        $artDirection[$key] = $value;
                    }
                }

                $flatScene = trim(($artDirection['action'] ?? '').' '.($artDirection['detail'] ?? ''));

                $pages[] = [
                    'text' => PromptText::stringify($rawText),
                    'scene' => $flatScene !== '' ? $flatScene : PromptText::stringify($rawText),
                    'artDirection' => $artDirection !== [] ? $artDirection : null,
                ];

                continue;
            }

            $pages[] = [
                'text' => PromptText::stringify($rawText),
                'scene' => PromptText::stringify($rawScene ?? $rawText),
                'artDirection' => null,
            ];
        }

        $colorScript = null;

        if (is_array($parsed['colorScript'] ?? null)) {
            $colorScript = array_values(array_filter(array_map(
                fn ($note): string => trim(PromptText::stringify($note)),
                $parsed['colorScript'],
            ), fn (string $note): bool => $note !== ''));

            if ($colorScript === []) {
                $colorScript = null;
            }
        }

        $cover = null;

        if (is_array($parsed['cover'] ?? null)) {
            $cover = array_filter([
                'moment' => PromptText::line($parsed['cover']['moment'] ?? null, 600),
                'titleStyle' => PromptText::line($parsed['cover']['titleStyle'] ?? null, 300),
            ], fn (?string $value): bool => $value !== null);

            if ($cover === []) {
                $cover = null;
            }
        }

        return [
            'subtitle' => PromptText::line($parsed['subtitle'] ?? null, 60),
            'world' => PromptText::line($parsed['world'] ?? null, 800),
            'motif' => PromptText::line($parsed['motif'] ?? null, 200),
            'refrain' => PromptText::line($parsed['refrain'] ?? null, 200),
            'colorScript' => $colorScript,
            'cover' => $cover,
            'pages' => $pages,
        ];
    }

    /**
     * Generate and store the hero's character sheet: one canonical stylized
     * rendition of the main character that anchors the cover and every page.
     * Failures are non-fatal; generation simply continues unanchored.
     */
    private function prepareHeroSheet(Book $book, Character $main): ?ImageReference
    {
        try {
            return $this->storeHeroSheet($book, $main);
        } catch (Throwable $exception) {
            Log::warning("Hero sheet generation failed for book {$book->id}, continuing without an anchor: {$exception->getMessage()}");

            return $this->anchorFor($book);
        }
    }

    /**
     * The hero's character sheet, reused from the character's portrait
     * library when this style was already drawn once (the
     * photo-to-illustration jump happens once per character and style, ever).
     * A newly generated sheet is saved into that library for every future
     * book. Unlike prepareHeroSheet this surfaces failures to the caller.
     */
    private function storeHeroSheet(Book $book, Character $main): ImageReference
    {
        $style = $this->effectiveStyle($book);

        $cached = CharacterPortrait::query()
            ->where('character_id', $main->id)
            ->where('art_style', $style)
            ->first();

        if ($cached !== null && MediaDisk::public()->exists($cached->path)) {
            $book->update(['hero_sheet_path' => $cached->path, 'hero_sheet_prompt' => $cached->prompt]);
            Log::info('Reusing the cached character portrait as the sheet (no generation).', ['character_id' => $main->id, 'art_style' => $style, 'path' => $cached->path]);

            return new ImageReference($cached->path, "{$main->name} (character sheet)");
        }

        return $this->drawAndStoreHeroSheet($book, $main, $style);
    }

    /**
     * Regenerate the main character's PORTRAIT only. Draws a fresh sheet and
     * overwrites the character's saved portrait for this style, which every
     * book using that character shares. The book is never touched: no page,
     * no cover, not even the book's own sheet pointer changes here.
     */
    public function regenerateCharacterPortrait(Book $book): void
    {
        try {
            $this->startFlowSession($book);
            $cast = $this->castFor($book);
            $this->ensureCast($cast, $book->art_style);
            $main = $this->mainCharacter($cast);

            if ($main === null) {
                throw new RuntimeException('Book has no characters');
            }

            $style = $this->effectiveStyle($book);
            [$image, $engine] = $this->generateHeroSheetImage($book, $main);
            $path = sprintf('portraits/%d/%s-%s.png', $main->id, $style, Str::lower(Str::random(8)));
            $this->images->storeGenerated($image->bytes, $path);

            // Only the character's portrait is updated (shared across every
            // book that uses this character). The book stays exactly as it is.
            CharacterPortrait::query()->updateOrCreate(
                ['character_id' => $main->id, 'art_style' => $style],
                ['path' => $path, 'prompt' => $image->prompt, ...$engine],
            );

            Log::info('Character portrait regenerated.', ['character_id' => $main->id, 'art_style' => $style, 'path' => $path]);
        } catch (Throwable $exception) {
            Log::error("Failed to regenerate the character portrait for book {$book->id}: {$exception->getMessage()}");
        } finally {
            $this->usage->flush($book->id);
        }
    }

    /**
     * Draw a fresh sheet, store it under the character's portrait folder,
     * point the book at it, cache it as the character's portrait for this
     * style, and record a restorable version. Used during a full book run.
     */
    private function drawAndStoreHeroSheet(Book $book, Character $main, string $style): ImageReference
    {
        [$image, $engine] = $this->generateHeroSheetImage($book, $main);
        $path = sprintf('portraits/%d/%s-%s.png', $main->id, $style, Str::lower(Str::random(8)));

        $this->images->storeGenerated($image->bytes, $path);
        $book->update(['hero_sheet_path' => $path, 'hero_sheet_prompt' => $image->prompt]);

        CharacterPortrait::query()->updateOrCreate(
            ['character_id' => $main->id, 'art_style' => $style],
            ['path' => $path, 'prompt' => $image->prompt, ...$engine],
        );

        // The replaced file stays on disk as a restorable version.
        ImageVersion::query()->create(['book_id' => $book->id, 'slot' => 'sheet', 'path' => $path, 'prompt' => $image->prompt, ...$engine]);
        Log::info('Character sheet stored.', ['path' => $path, 'attempt' => $image->attempt]);

        return new ImageReference($path, "{$main->name} (character sheet)");
    }

    /**
     * A reference image per cast member id, to be used instead of the raw
     * photo: the hero's sheet for the main character, and a stylized portrait
     * (drawn once per character + style, cached) for every photographed
     * supporting character. Characters without a usable photo, or in photo
     * mode, are omitted and fall back to their photo/description downstream.
     *
     * @param  Collection<int, Character>  $cast
     * @return array<int, ImageReference>
     */
    private function castPortraits(Book $book, Collection $cast, Character $main, ?ImageReference $anchor): array
    {
        $style = $this->effectiveStyle($book);
        $map = [];

        if ($anchor !== null) {
            $map[$main->id] = $anchor;
        }

        foreach ($cast as $member) {
            if ($member->id === $main->id) {
                continue;
            }

            if ($this->references->anchorsWithSheet($member) && $this->references->hasUsablePhoto($member)) {
                $map[$member->id] = $this->supportingPortrait($book, $member, $style);
            }
        }

        return $map;
    }

    /**
     * A photographed supporting character's stylized portrait for this style,
     * reused from the character's library when already drawn, else generated
     * once and cached. Unlike the hero sheet it never touches the book (no
     * hero_sheet_path, no book version): it belongs to the character.
     */
    private function supportingPortrait(Book $book, Character $member, string $style): ImageReference
    {
        $cached = CharacterPortrait::query()
            ->where('character_id', $member->id)
            ->where('art_style', $style)
            ->first();

        if ($cached !== null && MediaDisk::public()->exists($cached->path)) {
            return new ImageReference($cached->path, "{$member->name} (character sheet)");
        }

        [$image, $engine] = $this->generateHeroSheetImage($book, $member);
        $path = sprintf('portraits/%d/%s-%s.png', $member->id, $style, Str::lower(Str::random(8)));
        $this->images->storeGenerated($image->bytes, $path);

        CharacterPortrait::query()->updateOrCreate(
            ['character_id' => $member->id, 'art_style' => $style],
            ['path' => $path, 'prompt' => $image->prompt, ...$engine],
        );

        Log::info('Supporting character portrait stored.', ['character_id' => $member->id, 'art_style' => $style, 'path' => $path]);

        return new ImageReference($path, "{$member->name} (character sheet)");
    }

    /**
     * The style this run actually draws in: the per-run admin override when
     * one is active, else the book's stored style.
     */
    private function effectiveStyle(Book $book): string
    {
        $override = (string) config('cubfable.ai.style_override', '');

        return $override !== '' ? $override : $book->art_style;
    }

    /**
     * Generate the sheet image, returning it together with the engine stamp
     * captured INSIDE the portrait-engine scope (afterwards the main engine
     * is already restored and the stamp would lie).
     *
     * @return array{0: GeneratedImage, 1: array{engine_provider: string, engine_model: string}}
     */
    private function generateHeroSheetImage(Book $book, Character $main): array
    {
        ['prompt' => $prompt, 'references' => $references] = $this->imagePrompts->sheet($book, $main);

        // The sheet anchors identity for every other image, so it never
        // walks the engine chain: a sheet drawn by a different engine would
        // subtly change the hero's look across the whole book.
        return $this->withPortraitEngine(fn (): array => [
            $this->safeImages->generate($prompt, $this->sizes->sheetSize(), $references, 'character sheet', new PromptLogContext($book->id, 'character-sheet'), null, false),
            $this->currentEngine(),
        ]);
    }

    /**
     * The stored hero sheet as an image reference, when it exists on disk.
     * Older books generated before anchoring simply return null and keep
     * their original behavior.
     */
    private function anchorFor(Book $book): ?ImageReference
    {
        $path = $book->hero_sheet_path;

        if ($path === null || $path === '' || ! MediaDisk::public()->exists($path)) {
            return null;
        }

        return new ImageReference($path, "{$book->child_name} (character sheet)");
    }

    /**
     * A character's saved portrait for a style as a reference, when one exists
     * on disk. Read-only: never generates. This is what lets a manual
     * regeneration pick up a portrait created after the book was first made.
     */
    private function cachedPortraitReference(Character $character, string $style): ?ImageReference
    {
        $portrait = CharacterPortrait::query()
            ->where('character_id', $character->id)
            ->where('art_style', $style)
            ->first();

        if ($portrait === null || ! MediaDisk::public()->exists($portrait->path)) {
            return null;
        }

        return new ImageReference($portrait->path, "{$character->name} (character sheet)");
    }

    /**
     * The reference per cast member for a manual regeneration: the same
     * portrait-over-photo rule as a full run, but read-only - it reuses saved
     * portraits and never generates a new one, so regenerating a single image
     * never triggers surprise portrait generation.
     *
     * @param  Collection<int, Character>  $cast
     * @return array<int, ImageReference>
     */
    private function cachedCastPortraits(Book $book, Collection $cast, Character $main, ?ImageReference $anchor): array
    {
        $style = $this->effectiveStyle($book);
        $map = [];

        if ($anchor !== null) {
            $map[$main->id] = $anchor;
        }

        foreach ($cast as $member) {
            if ($member->id === $main->id) {
                continue;
            }

            $ref = $this->cachedPortraitReference($member, $style);

            if ($ref !== null) {
                $map[$member->id] = $ref;
            }
        }

        return $map;
    }

    /**
     * The main character's reference for a manual regeneration in sheet mode:
     * the book's own sheet when it has one, else the character's saved
     * portrait for this style (so a portrait created after the book was made
     * is used instead of falling back to the raw photo).
     */
    private function manualRegenAnchor(Book $book, Character $main): ?ImageReference
    {
        if (! $this->references->anchorsWithSheet($main)) {
            return null;
        }

        return $this->anchorFor($book) ?? $this->cachedPortraitReference($main, $this->effectiveStyle($book));
    }

    /**
     * @param  Collection<int, Character>  $cast
     */
    /**
     * @param  Collection<int, Character>  $cast
     * @param  array<int, ImageReference>  $castPortraits
     */
    private function generatePageImage(Page $page, Book $book, Collection $cast, Character $main, ?ImageReference $anchor = null, array $castPortraits = []): GeneratedImage
    {
        // Composed per engine: a fallback engine gets a prompt built for its
        // own reference budget, never a stale one with dangling pointers.
        $compose = fn (): array => $this->imagePrompts->page($book, $page, $cast, $main, $anchor, $castPortraits);

        return $this->safeImages->generate('', $this->sizes->bookSize(), [], "page {$page->page_number}", new PromptLogContext($book->id, 'page', $page->id), $compose);
    }

    /**
     * Generate the cover image WITH its title rendered in the artwork. The
     * decorative gold frame is added by the UI (BookCover), so the prompt asks
     * for a clean margin and no border.
     */
    private function generateCoverImage(Book $book, Character $main, ?ImageReference $anchor = null): GeneratedImage
    {
        // Composed per engine, like the pages.
        $compose = fn (): array => $this->imagePrompts->cover($book, $main, $anchor);

        return $this->safeImages->generate('', $this->sizes->bookSize(), [], 'cover', new PromptLogContext($book->id, 'cover'), $compose);
    }

    /**
     * Generate and store the cover (at the configured aspect ratio),
     * replacing any previous file. The whole step runs under the dedicated
     * cover engine when one is configured, so the prompt's reference budget,
     * the generation itself and the version stamp all agree on the engine.
     */
    private function storeCover(Book $book, Character $main, ?ImageReference $anchor = null): void
    {
        $this->withCoverEngine(function () use ($book, $main, $anchor): void {
            $image = $this->generateCoverImage($book, $main, $anchor);
            $path = sprintf('books/%d/cover-%s.png', $book->id, Str::lower(Str::random(8)));

            $this->images->storeGenerated($image->bytes, $path);
            $book->update(['cover_image_path' => $path, 'cover_prompt' => $image->prompt, 'cover_flagged_at' => null]);

            // The replaced file stays on disk as a restorable version.
            ImageVersion::query()->create(['book_id' => $book->id, 'slot' => 'cover', 'path' => $path, 'prompt' => $image->prompt, ...$this->currentEngine()]);
            Log::info('Cover stored.', ['path' => $path, 'attempt' => $image->attempt]);
        });
    }

    /**
     * Run the callback with the dedicated cover engine applied. A blank
     * cover provider means the cover follows the main engine; an explicit
     * per-run admin override (EngineOverride) always wins. The original
     * config is restored afterwards so the pages keep their own engine.
     */
    /**
     * Run the callback under the dedicated portrait engine when one is
     * configured: the character sheet is the single image where a strong
     * stylizer pays off most, since every other image inherits its look.
     * An explicit per-run admin engine override still wins.
     *
     * @param  callable(): array{0: GeneratedImage, 1: array{engine_provider: string, engine_model: string}}  $callback
     * @return array{0: GeneratedImage, 1: array{engine_provider: string, engine_model: string}}
     */
    private function withPortraitEngine(callable $callback): array
    {
        $provider = trim((string) config('cubfable.ai.portrait_image_provider'));

        if ($provider === '' || (bool) config('cubfable.ai.engine_override_active')) {
            return $callback();
        }

        $model = trim((string) config('cubfable.ai.portrait_image_model'));
        $modelPath = "cubfable.ai.models.image.{$provider}";
        $originalProvider = config('cubfable.ai.image_provider');
        $originalModel = config($modelPath);

        config()->set('cubfable.ai.image_provider', $provider);

        if ($model !== '') {
            config()->set($modelPath, $model);
        }

        Log::info('Portrait engine engaged for this character sheet.', ['provider' => $provider, 'model' => $model !== '' ? $model : (string) config($modelPath)]);

        try {
            return $callback();
        } finally {
            config()->set('cubfable.ai.image_provider', $originalProvider);
            config()->set($modelPath, $originalModel);
        }
    }

    private function withCoverEngine(callable $callback): void
    {
        $provider = trim((string) config('cubfable.ai.cover_image_provider'));

        if ($provider === '' || (bool) config('cubfable.ai.engine_override_active')) {
            $callback();

            return;
        }

        $model = trim((string) config('cubfable.ai.cover_image_model'));
        $modelPath = "cubfable.ai.models.image.{$provider}";
        $originalProvider = config('cubfable.ai.image_provider');
        $originalModel = config($modelPath);

        config()->set('cubfable.ai.image_provider', $provider);

        if ($model !== '') {
            config()->set($modelPath, $model);
        }

        Log::info('Cover engine engaged for this cover.', ['provider' => $provider, 'model' => $model !== '' ? $model : (string) config($modelPath)]);

        try {
            $callback();
        } finally {
            config()->set('cubfable.ai.image_provider', $originalProvider);
            config()->set($modelPath, $originalModel);
        }
    }

    /**
     * Generate and store one page illustration (at the configured aspect
     * ratio), replacing any previous file, and mark the page complete.
     *
     * @param  Collection<int, Character>  $cast
     * @param  array<int, ImageReference>  $castPortraits
     */
    private function storePageIllustration(Page $page, Book $book, Collection $cast, Character $main, ?ImageReference $anchor = null, array $castPortraits = []): void
    {
        $image = $this->generatePageImage($page, $book, $cast, $main, $anchor, $castPortraits);
        $path = sprintf('books/%d/pages/%d-%s.png', $book->id, $page->page_number, Str::lower(Str::random(8)));

        $this->images->storeGenerated($image->bytes, $path);
        $page->update(['image_path' => $path, 'image_prompt' => $image->prompt, 'status' => PageStatus::Complete, 'flagged_at' => null]);

        // The replaced file stays on disk as a restorable version.
        ImageVersion::query()->create(['book_id' => $book->id, 'page_id' => $page->id, 'page_number' => $page->page_number, 'slot' => 'page', 'path' => $path, 'prompt' => $image->prompt, ...$this->currentEngine()]);
        Log::info("Page {$page->page_number} illustration stored.", ['path' => $path, 'attempt' => $image->attempt]);
    }

    /**
     * Whether the whole page set can render as one grouped request.
     */
    private function groupGenerationAvailable(int $pageCount): bool
    {
        return $pageCount > 1
            && (bool) config('cubfable.ai.group_generation')
            && $this->ai->supportsImageGroups();
    }

    /**
     * Render the pages as one coherent set - or, when the whole book cannot
     * fit one request (prompt cap or the engine's 15-image budget), as the
     * fewest contiguous batches that do fit. Every batch carries the same
     * references, and batches after the first also carry a finished page
     * from the first batch as a style anchor so the whole book stays one
     * look. Anything unfitting or unreturned falls to the per-page loop.
     *
     * @param  list<Page>  $pages
     * @param  Collection<int, Character>  $cast
     * @param  array<int, ImageReference>  $castPortraits
     */
    private function storePagesAsGroup(Book $book, array $pages, Collection $cast, Character $main, ?ImageReference $anchor, array $castPortraits = []): void
    {
        $batches = $this->planGroupBatches($book, $pages, $cast, $main, $anchor, $castPortraits);

        if ($batches === []) {
            Log::info('No grouped batch layout fits the engine limits; using page-by-page generation.', ['pages' => count($pages)]);

            return;
        }

        Log::info(count($batches) === 1
            ? 'Rendering all pages as one coherent set.'
            : 'Rendering the pages as '.count($batches).' coherent batches (style-anchored to the first).', [
                'pages' => count($pages),
                'batches' => array_map(fn (array $batch): int => count($batch), $batches),
            ]);

        $styleAnchor = null;

        foreach ($batches as $batchPages) {
            $this->abortIfStopRequested($book);

            ['prompt' => $prompt, 'references' => $references] = $this->imagePrompts->pageGroup($book, $batchPages, $cast, $main, $anchor, $styleAnchor, $castPortraits);

            $images = $this->ai->generateImageGroup($prompt, $this->sizes->bookSize(), $references, count($batchPages));

            foreach (array_values($batchPages) as $index => $page) {
                if (! isset($images[$index])) {
                    Log::warning('The group returned fewer images than pages; the rest generate page-by-page.', [
                        'returned' => count($images),
                        'pages' => count($batchPages),
                    ]);

                    return;
                }

                $this->persistGroupPageImage($book, $page, $images[$index], $prompt);

                if ($styleAnchor === null) {
                    $styleAnchor = new ImageReference((string) $page->image_path, 'style anchor');
                }
            }
        }
    }

    /**
     * Split the pages into the fewest contiguous batches where every batch
     * fits both the prompt-character cap and the engine's total image
     * budget. Empty when no layout of 2+ page batches fits.
     *
     * @param  list<Page>  $pages
     * @param  Collection<int, Character>  $cast
     * @param  array<int, ImageReference>  $castPortraits
     * @return list<list<Page>>
     */
    private function planGroupBatches(Book $book, array $pages, Collection $cast, Character $main, ?ImageReference $anchor, array $castPortraits = []): array
    {
        $total = count($pages);
        $maxBatches = max(1, min(4, intdiv($total, 2)));

        for ($batchCount = 1; $batchCount <= $maxBatches; $batchCount++) {
            $batches = array_chunk($pages, (int) ceil($total / $batchCount));
            $allFit = true;

            foreach ($batches as $index => $batchPages) {
                if (! $this->groupBatchFits($book, $batchPages, $cast, $main, $anchor, needsStyleAnchor: $index > 0, castPortraits: $castPortraits)) {
                    $allFit = false;

                    break;
                }
            }

            if ($allFit) {
                return $batches;
            }
        }

        return [];
    }

    /**
     * Whether one candidate batch fits the engine limits. Later batches
     * reserve room for the style-anchor reference and its constraint line.
     *
     * @param  list<Page>  $batchPages
     * @param  Collection<int, Character>  $cast
     * @param  array<int, ImageReference>  $castPortraits
     */
    private function groupBatchFits(Book $book, array $batchPages, Collection $cast, Character $main, ?ImageReference $anchor, bool $needsStyleAnchor, array $castPortraits = []): bool
    {
        ['prompt' => $prompt, 'references' => $references] = $this->imagePrompts->pageGroup($book, $batchPages, $cast, $main, $anchor, null, $castPortraits);

        $referenceCount = count($references) + ($needsStyleAnchor ? 1 : 0);
        $charBudget = 3800 - ($needsStyleAnchor ? 150 : 0);

        return mb_strlen($prompt) <= $charBudget
            && count($batchPages) <= 15 - $referenceCount;
    }

    /**
     * Store one page image produced by a grouped render: same storage,
     * version and journal treatment as the per-page path.
     */
    private function persistGroupPageImage(Book $book, Page $page, string $bytes, string $prompt): void
    {
        $path = sprintf('books/%d/pages/%d-%s.png', $book->id, $page->page_number, Str::lower(Str::random(8)));

        $this->images->storeGenerated($bytes, $path);
        $page->update(['image_path' => $path, 'image_prompt' => $prompt, 'status' => PageStatus::Complete]);

        ImageVersion::query()->create(['book_id' => $book->id, 'page_id' => $page->id, 'page_number' => $page->page_number, 'slot' => 'page', 'path' => $path, 'prompt' => $prompt, ...$this->currentEngine()]);

        try {
            ImagePrompt::query()->create([
                'book_id' => $book->id,
                'page_id' => $page->id,
                'purpose' => 'page',
                'attempt' => 1,
                'variant' => 'group',
                'prompt' => $prompt,
                'accepted' => true,
            ]);
        } catch (Throwable $exception) {
            Log::warning("Failed to journal a grouped image prompt: {$exception->getMessage()}");
        }

        Log::info("Page {$page->page_number} illustration stored (group).", ['path' => $path]);
    }

    /**
     * The image engine in effect for this process, stamped onto every stored
     * image version so model experiments stay attributable.
     *
     * @return array{engine_provider: string, engine_model: string}
     */
    private function currentEngine(): array
    {
        $provider = (string) config('cubfable.ai.image_provider');

        return [
            'engine_provider' => $provider,
            'engine_model' => (string) config("cubfable.ai.models.image.{$provider}"),
        ];
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
}
