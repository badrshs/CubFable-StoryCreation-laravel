<?php

namespace App\Services\Pdf;

use App\Models\Book;
use App\Models\Page;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TCPDF;
use TCPDF_FONTS;
use Throwable;

/**
 * Print-grade storybook PDF, composed programmatically (no HTML).
 *
 * - Trim: one of the PageSize presets (square 210 x 210 mm by default - the
 *   classic premium picture-book format). The layout is proportional: a
 *   full-width art band on top with the story text beneath, so portrait
 *   trims gain text room and landscape trims gain art width.
 * - Two variants: "print" carries 3mm bleed, crop marks and the full set of
 *   prepress boxes (MediaBox / BleedBox / TrimBox / ArtBox) so a print shop
 *   can impose and trim it with no fixes; "home" is trim-only with no marks.
 * - Illustrations are downsampled to 300 DPI at their placed size and
 *   transcoded to JPEG before embedding, which keeps a full book to a few MB
 *   instead of tens.
 * - The book's font choice (playful / classic / handwritten / bold) styles the
 *   story text and display lines; front/back matter keeps the Cormorant house
 *   style. Non-Latin story languages switch to a script-capable font, with
 *   right-to-left layout for Arabic script.
 * - Matter: cover, half-title, imprint, dedication, story pages, "The End",
 *   back cover.
 *
 * Ported from the pdf-lib implementation. pdf-lib uses a bottom-left origin
 * while TCPDF uses a top-left one, so all layout math stays in the original
 * bottom-left space and each primitive flips coordinates at the last moment.
 */
class StorybookPdfBuilder
{
    /**
     * Points per millimetre.
     */
    private const float MM = 2.834645669;

    /**
     * Safe margin inside the trim.
     */
    private const float MARGIN = 15 * self::MM;

    /**
     * Downsampling target for placed illustrations.
     */
    private const int TARGET_DPI = 300;

    private const int JPEG_QUALITY = 82;

    /** @var array{int, int, int} #211B3A */
    private const array INK = [33, 27, 58];

    /** @var array{int, int, int} #4B3FA0 */
    private const array INDIGO = [75, 63, 160];

    /** @var array{int, int, int} #2A2170 */
    private const array INDIGO_MID = [42, 33, 112];

    /** @var array{int, int, int} #12112B */
    private const array INK_NIGHT = [18, 17, 43];

    /** @var array{int, int, int} #F2B23E */
    private const array GOLD = [242, 178, 62];

    /** @var array{int, int, int} */
    private const array GOLD_SOFT = [250, 209, 128];

    /** @var array{int, int, int} #FBF5EA */
    private const array PAPER = [251, 245, 234];

    /** @var array{int, int, int} #F6EFE1 */
    private const array CREAM = [246, 239, 225];

    /** @var array{int, int, int} */
    private const array MOON = [242, 184, 74];

    /** @var array{int, int, int} */
    private const array MUTED = [112, 107, 135];

    /** @var array{int, int, int} back-cover stars */
    private const array STARLIGHT = [217, 224, 255];

    /** @var array{int, int, int} missing-illustration placeholder */
    private const array LILAC = [237, 232, 247];

    /** @var array{int, int, int} back-cover eyebrow text */
    private const array PERIWINKLE = [191, 199, 242];

    /** @var array{int, int, int} */
    private const array CROP_MARK = [26, 26, 31];

    /** @var array<string, string> layout role => bundled TTF file (the house matter style) */
    private const array FONT_FILES = [
        'title' => 'Cormorant-Bold.ttf',
        'semi' => 'Cormorant-SemiBold.ttf',
        'body' => 'Cormorant-Medium.ttf',
        'italic' => 'Cormorant-Italic.ttf',
        'accent' => 'Baloo2-SemiBold.ttf',
    ];

    /**
     * The book's font choice => the TTFs used for story text and display
     * lines. Classic keeps the house serif; the others carry their own voice.
     *
     * @var array<string, array{story: string, display: string}>
     */
    private const array STORY_FONT_CHOICES = [
        'playful' => ['story' => 'Baloo2-SemiBold.ttf', 'display' => 'Baloo2-SemiBold.ttf'],
        'classic' => ['story' => 'Cormorant-Medium.ttf', 'display' => 'Cormorant-Bold.ttf'],
        'handwritten' => ['story' => 'PatrickHand-Regular.ttf', 'display' => 'PatrickHand-Regular.ttf'],
        'bold' => ['story' => 'LuckiestGuy-Regular.ttf', 'display' => 'LuckiestGuy-Regular.ttf'],
    ];

    /**
     * Story languages whose script the Latin fonts cannot render, mapped to a
     * capable font: professional Arabic faces (see ARABIC_STORY_FONTS) for
     * Arabic script with Amiri as the safety net, and TCPDF's own
     * broad-coverage families elsewhere. Arabic-script languages also flip the
     * story text to RTL.
     *
     * @var array<string, array{font: ?string, family: ?string, rtl: bool}>
     */
    private const array SCRIPT_OVERRIDES = [
        'ar' => ['font' => 'Amiri-Regular.ttf', 'family' => null, 'rtl' => true],
        'ur' => ['font' => 'Amiri-Regular.ttf', 'family' => null, 'rtl' => true],
        'ru' => ['font' => null, 'family' => 'dejavusans', 'rtl' => false],
        'hi' => ['font' => null, 'family' => 'freeserif', 'rtl' => false],
        'zh' => ['font' => null, 'family' => 'cid0cs', 'rtl' => false],
    ];

    /**
     * The book's font choice carried into Arabic script. TCPDF renders
     * Arabic through the legacy Unicode presentation-forms block, and only a
     * handful of families ship those glyphs (a scan of 26 Google families
     * found exactly four) - every face here is verified to actually render,
     * not just embed.
     *
     * @var array<string, string>
     */
    private const array ARABIC_STORY_FONTS = [
        'classic' => 'NotoNaskhArabic-Regular.ttf',
        'playful' => 'Vazirmatn-Regular.ttf',
        'handwritten' => 'Amiri-Regular.ttf',
        'bold' => 'IBMPlexSansArabic-Bold.ttf',
    ];

    /**
     * The bundled faces an admin can name in the per-language font settings
     * (pdf_font_default / pdf_font_<lang>), keyed by their slug. Any other
     * non-empty value is treated as a Google Fonts family name and
     * downloaded at build time.
     *
     * @var array<string, string>
     */
    private const array BUNDLED_FACES = [
        'noto-naskh' => 'NotoNaskhArabic-Regular.ttf',
        'amiri' => 'Amiri-Regular.ttf',
        'vazirmatn' => 'Vazirmatn-Regular.ttf',
        'plex-arabic' => 'IBMPlexSansArabic-Regular.ttf',
        'plex-arabic-bold' => 'IBMPlexSansArabic-Bold.ttf',
        'baloo' => 'Baloo2-SemiBold.ttf',
        'cormorant' => 'Cormorant-Medium.ttf',
        'patrick-hand' => 'PatrickHand-Regular.ttf',
        'luckiest-guy' => 'LuckiestGuy-Regular.ttf',
    ];

    /**
     * The bundled face slugs, for the settings UI hint.
     *
     * @return list<string>
     */
    public static function bundledFaceKeys(): array
    {
        return array_keys(self::BUNDLED_FACES);
    }

    /** @var array<string, array{family: string, style: string, file: string}> core font stand-ins used when a TTF conversion fails */
    private const array CORE_FONT_FALLBACKS = [
        'title' => ['family' => 'times', 'style' => 'B', 'file' => ''],
        'semi' => ['family' => 'times', 'style' => 'B', 'file' => ''],
        'body' => ['family' => 'times', 'style' => '', 'file' => ''],
        'italic' => ['family' => 'times', 'style' => 'I', 'file' => ''],
        'accent' => ['family' => 'helvetica', 'style' => 'B', 'file' => ''],
        'story' => ['family' => 'times', 'style' => '', 'file' => ''],
        'display' => ['family' => 'times', 'style' => 'B', 'file' => ''],
    ];

    private TCPDF $pdf;

    /** @var array<string, array{family: string, style: string, file: string}> */
    private array $fonts = [];

    private float $bleed = 0.0;

    private float $trimW = 595.28;

    private float $trimH = 595.28;

    private float $pageW = 595.28;

    private float $pageH = 595.28;

    private float $cx = 595.28 / 2;

    private bool $printMarks = false;

    private bool $rtl = false;

    /**
     * How book art sits on the page: 'crop' fills (edges cropped), 'full'
     * shows the whole image above the text, 'overlay' shows the whole image
     * full-page with the story text on a translucent panel over it.
     */
    private string $imageFit = 'crop';

    /** @var list<string> transcoded temp images to clean up */
    private array $tempImages = [];

    /**
     * Compose the complete storybook and return the raw PDF bytes.
     *
     * @param  string  $variant  print (bleed + crop marks) or home (trim only)
     * @param  string|null  $sizeKey  a PageSize preset key; null follows the
     *                                configured runtime setting
     * @param  string|null  $imageFit  'crop' (fill, edges cropped), 'full'
     *                                 (whole image above the text) or
     *                                 'overlay' (whole image full-page, text
     *                                 on a translucent panel); null follows
     *                                 the configured runtime setting
     */
    public function build(Book $book, string $variant = 'print', ?string $sizeKey = null, ?string $imageFit = null): string
    {
        $size = PageSize::fromKey($sizeKey ?? (string) config('cubfable.pdf.page_size', PageSize::DEFAULT));

        $fit = $imageFit ?? (string) config('cubfable.pdf.image_fit', 'crop');
        $this->imageFit = in_array($fit, ['full', 'overlay'], true) ? $fit : 'crop';
        $this->printMarks = $variant !== 'home';
        $this->bleed = $this->printMarks ? 3 * self::MM : 0.0;
        $this->trimW = $size->trimW;
        $this->trimH = $size->trimH;
        $this->pageW = $this->trimW + 2 * $this->bleed;
        $this->pageH = $this->trimH + 2 * $this->bleed;
        $this->cx = $this->bleed + $this->trimW / 2;
        $this->rtl = self::SCRIPT_OVERRIDES[$book->language]['rtl'] ?? false;

        try {
            $this->pdf = $this->makeDocument($book);
            $this->fonts = $this->resolveFonts($book);

            $this->drawCoverPage($book);
            $this->drawTitlePage($book);
            $this->drawImprintPage($book);
            $this->drawDedicationPage($book);

            foreach ($book->pages()->orderBy('page_number')->get() as $page) {
                $this->drawStoryPage($page);
            }

            $this->drawEndPage($book);
            $this->drawBackCover($book);

            return $this->pdf->Output('', 'S');
        } finally {
            foreach ($this->tempImages as $tempImage) {
                @unlink($tempImage);
            }

            $this->tempImages = [];
        }
    }

    /**
     * TCPDF swaps page dimensions to match the declared orientation, so a
     * landscape trim must be declared 'L' or it silently flips to portrait.
     */
    private function orientation(): string
    {
        return $this->pageW > $this->pageH ? 'L' : 'P';
    }

    private function makeDocument(Book $book): TCPDF
    {
        $pdf = new TCPDF($this->orientation(), 'pt', [$this->pageW, $this->pageH], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCellMargins(0, 0, 0, 0);

        $pdf->SetTitle("{$book->child_name}'s Storybook");
        $pdf->SetAuthor('CubFable');
        $pdf->SetCreator('CubFable');
        $pdf->SetSubject('A personalized CubFable storybook');
        $pdf->SetKeywords("storybook, children, personalized, CubFable, {$book->theme}");

        return $pdf;
    }

    // ---------------------------------------------------------------- fonts

    /**
     * Convert the bundled TTFs once (cached under storage) and map each layout
     * role to a TCPDF font definition. The story/display roles follow the
     * book's font choice, overridden by a script-capable font when the story
     * language needs one.
     *
     * @return array<string, array{family: string, style: string, file: string}>
     */
    private function resolveFonts(Book $book): array
    {
        $cacheDir = storage_path('app/tcpdf-fonts/');
        File::ensureDirectoryExists($cacheDir);

        $fonts = [];

        foreach (self::FONT_FILES as $role => $ttf) {
            $fonts[$role] = $this->convertTtf($ttf, $cacheDir) ?? self::CORE_FONT_FALLBACKS[$role];
        }

        $choice = self::STORY_FONT_CHOICES[$book->font] ?? self::STORY_FONT_CHOICES['classic'];
        $override = self::SCRIPT_OVERRIDES[$book->language] ?? null;

        // An admin-configured face wins: the language's own font setting
        // first, then the default-for-all-languages one; each names a
        // bundled face or a Google Font to download. Unset (or a failed
        // download/conversion) falls through to the automatic behavior.
        $configured = $this->configuredStoryTtf($book);
        $configuredFace = $configured !== null ? $this->convertTtf($configured, $cacheDir) : null;

        // TCPDF renders Arabic through the legacy presentation-forms block;
        // a font without those glyphs embeds fine but prints boxes. Reject
        // it here so a wrong pick degrades to a working face, never to tofu.
        if ($configuredFace !== null && ($override['rtl'] ?? false) && ! $this->coversArabicPresentationForms($configuredFace)) {
            Log::warning('Storybook PDF: the configured face has no Arabic presentation forms (it would render boxes); using the automatic Arabic face instead.', ['face' => $configuredFace['family']]);
            $configuredFace = null;
        }

        if ($configuredFace !== null) {
            $fonts['story'] = $configuredFace;
            $fonts['display'] = $configuredFace;
        } elseif ($override !== null) {
            // Automatic: Arabic script picks the professional face matching
            // the book's font choice, with Amiri as the safety net; other
            // scripts use a capable TCPDF family.
            $ttf = $override['font'];

            if ($ttf !== null && $override['rtl'] && isset(self::ARABIC_STORY_FONTS[$book->font])) {
                $ttf = self::ARABIC_STORY_FONTS[$book->font];
            }

            if ($ttf !== null) {
                $script = $this->convertTtf($ttf, $cacheDir);

                if ($script === null && $ttf !== 'Amiri-Regular.ttf') {
                    $script = $this->convertTtf('Amiri-Regular.ttf', $cacheDir);
                }

                $script ??= self::CORE_FONT_FALLBACKS['story'];
            } else {
                $script = ['family' => (string) $override['family'], 'style' => '', 'file' => ''];
            }

            $fonts['story'] = $script;
            $fonts['display'] = $script;
        } else {
            $fonts['story'] = $this->convertTtf($choice['story'], $cacheDir) ?? self::CORE_FONT_FALLBACKS['story'];
            $fonts['display'] = $this->convertTtf($choice['display'], $cacheDir) ?? self::CORE_FONT_FALLBACKS['display'];
        }

        return $fonts;
    }

    /**
     * The story TTF the admin's font settings ask for on this book: the
     * language's own setting first, then the default for all languages.
     * Each value is a bundled face slug or a Google Fonts family name.
     * Returns a bundled filename, an absolute path to a downloaded font, or
     * null when nothing is configured ('auto'/empty) so the automatic
     * per-script behavior applies.
     */
    private function configuredStoryTtf(Book $book): ?string
    {
        $spec = trim((string) config("cubfable.pdf.fonts.{$book->language}", ''));

        if ($spec === '') {
            $spec = trim((string) config('cubfable.pdf.fonts.default', ''));
        }

        if ($spec === '' || strcasecmp($spec, 'auto') === 0) {
            return null;
        }

        $bundled = self::BUNDLED_FACES[Str::slug($spec)] ?? null;

        return $bundled ?? $this->googleFontTtf($spec, $this->googleFontSubset($book->language));
    }

    /**
     * The Google Fonts subset the story language needs. Without it the CSS
     * API serves the Latin-only file and every non-Latin glyph is missing
     * from the downloaded font.
     */
    private function googleFontSubset(string $language): string
    {
        return match ($language) {
            'ar', 'ur' => 'arabic,latin',
            'ru' => 'cyrillic,latin',
            'hi' => 'devanagari,latin',
            'zh' => 'chinese-simplified,latin',
            default => 'latin,latin-ext',
        };
    }

    /**
     * Download a Google Font family's regular TTF by name (cached under
     * storage, per script subset) and return its absolute path, or null when
     * the family cannot be fetched. The v1 CSS API serves plain TrueType
     * urls to a default user agent, which is exactly what TCPDF can convert.
     */
    private function googleFontTtf(string $family, string $subset): ?string
    {
        if ($family === '') {
            return null;
        }

        $path = storage_path('app/pdf-fonts/google/'.Str::slug($family.' '.$subset).'.ttf');

        if (File::exists($path)) {
            return $path;
        }

        try {
            $css = Http::timeout(20)->get('https://fonts.googleapis.com/css', ['family' => $family, 'subset' => $subset])->throw()->body();

            if (preg_match('/url\((https:[^)]+\.ttf)\)/', $css, $matches) !== 1) {
                Log::warning("Storybook PDF: Google Fonts returned no TTF url for [{$family}].");

                return null;
            }

            $bytes = Http::timeout(30)->get($matches[1])->throw()->body();

            File::ensureDirectoryExists(dirname($path));
            File::put($path, $bytes);

            return $path;
        } catch (Throwable $exception) {
            Log::warning("Storybook PDF: downloading Google Font [{$family}] failed; using the automatic Arabic face.", ['exception' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * TCPDF fuses a shadda followed by a vowel mark into one of the
     * U+FC5E-FC63 presentation ligatures, and several fonts give those
     * glyphs a SPACING advance - which rips a visible gap into the middle
     * of the word right where the shadda sits. Putting the vowel first
     * (canonically identical text) keeps the two marks as separate
     * zero-width combining glyphs that stack cleanly in every font.
     */
    private function normalizeArabicMarks(string $text): string
    {
        return (string) preg_replace('/\x{0651}([\x{064B}-\x{0650}\x{0670}])/u', '$1'."\u{0651}", $text);
    }

    /**
     * Whether a converted face carries the Arabic presentation-forms glyphs
     * TCPDF's shaper maps to (checked on ALEF isolated, LAM initial and MEEM
     * medial in the definition's width table).
     *
     * @param  array{family: string, style: string, file: string}  $face
     */
    private function coversArabicPresentationForms(array $face): bool
    {
        if ($face['file'] === '' || ! File::exists($face['file'])) {
            return false;
        }

        $cw = null; // populated by the TCPDF font definition file
        include $face['file'];

        return is_array($cw) && isset($cw[0xFE8D], $cw[0xFEDF], $cw[0xFEE4]);
    }

    /**
     * @return array{family: string, style: string, file: string}|null
     */
    private function convertTtf(string $ttf, string $cacheDir): ?array
    {
        try {
            $ttfPath = File::exists($ttf) ? $ttf : resource_path('fonts/'.$ttf);
            $family = TCPDF_FONTS::addTTFfont($ttfPath, 'TrueTypeUnicode', '', 96, $cacheDir);
        } catch (Throwable $exception) {
            Log::warning("Storybook PDF: converting font [{$ttf}] failed, falling back to a core font.", ['exception' => $exception->getMessage()]);

            return null;
        }

        if ($family === false) {
            Log::warning("Storybook PDF: converting font [{$ttf}] failed, falling back to a core font.");

            return null;
        }

        return ['family' => $family, 'style' => '', 'file' => $cacheDir.$family.'.php'];
    }

    private function useFont(string $role, float $size): void
    {
        $font = $this->fonts[$role];

        $this->pdf->SetFont($font['family'], $font['style'], $size, $font['file']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Add a leaf. Print variant leaves carry the full set of prepress boxes.
     */
    private function addLeaf(): void
    {
        if (! $this->printMarks) {
            $this->pdf->AddPage($this->orientation(), [$this->pageW, $this->pageH]);

            return;
        }

        $this->pdf->AddPage($this->orientation(), [
            'MediaBox' => ['llx' => 0, 'lly' => 0, 'urx' => $this->pageW, 'ury' => $this->pageH],
            'BleedBox' => ['llx' => 0, 'lly' => 0, 'urx' => $this->pageW, 'ury' => $this->pageH],
            'TrimBox' => [
                'llx' => $this->bleed,
                'lly' => $this->bleed,
                'urx' => $this->bleed + $this->trimW,
                'ury' => $this->bleed + $this->trimH,
            ],
            'ArtBox' => [
                'llx' => $this->bleed + self::MARGIN,
                'lly' => $this->bleed + self::MARGIN,
                'urx' => $this->bleed + $this->trimW - self::MARGIN,
                'ury' => $this->bleed + $this->trimH - self::MARGIN,
            ],
        ]);
    }

    /**
     * Convert a distance down from the trim's top edge into a bottom-left y.
     */
    private function topY(float $fromTop): float
    {
        return $this->bleed + $this->trimH - $fromTop;
    }

    /**
     * Flip a bottom-left y coordinate into TCPDF's top-left space.
     */
    private function flipY(float $y): float
    {
        return $this->pageH - $y;
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function fillPage(array $color): void
    {
        $this->fillRect(0, 0, $this->pageW, $this->pageH, $color);
    }

    /**
     * Fill a rectangle whose (x, y) is its bottom-left corner.
     *
     * @param  array{int, int, int}  $color
     */
    private function fillRect(float $x, float $y, float $w, float $h, array $color, float $alpha = 1.0): void
    {
        if ($alpha < 1.0) {
            $this->pdf->setAlpha($alpha);
        }

        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        $this->pdf->Rect($x, $this->flipY($y + $h), $w, $h, 'F');

        if ($alpha < 1.0) {
            $this->pdf->setAlpha(1);
        }
    }

    /**
     * Stroke a rectangle border whose (x, y) is its bottom-left corner.
     *
     * @param  array{int, int, int}  $color
     */
    private function strokeRect(float $x, float $y, float $w, float $h, array $color, float $lineWidth, float $alpha = 1.0): void
    {
        if ($alpha < 1.0) {
            $this->pdf->setAlpha($alpha);
        }

        $this->pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $this->pdf->SetLineWidth($lineWidth);
        $this->pdf->Rect($x, $this->flipY($y + $h), $w, $h, 'D');

        if ($alpha < 1.0) {
            $this->pdf->setAlpha(1);
        }
    }

    /**
     * Vertical gradient over a box whose (x, y) is its bottom-left corner.
     *
     * @param  array{int, int, int}  $top
     * @param  array{int, int, int}  $bottom
     */
    private function verticalGradient(float $x, float $y, float $w, float $h, array $top, array $bottom): void
    {
        $this->pdf->LinearGradient($x, $this->flipY($y + $h), $w, $h, $top, $bottom, [0, 1, 0, 0]);
    }

    /**
     * Draw text with its baseline anchored at a bottom-left y coordinate.
     */
    private function textAt(float $x, float $baseline, string $text): void
    {
        $this->pdf->Text($x, $this->flipY($baseline), $text, 0, false, true, 0, 0, '', false, '', 0, false, 'L');
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function centeredText(string $text, float $baseline, float $size, string $fontRole, array $color): void
    {
        $this->useFont($fontRole, $size);
        $this->pdf->SetTextColor($color[0], $color[1], $color[2]);

        $width = $this->pdf->GetStringWidth($text);
        $this->textAt($this->cx - $width / 2, $baseline, $text);
    }

    /**
     * Letter-spaced (tracked) centred text, used for the small-caps eyebrows.
     *
     * @param  array{int, int, int}  $color
     */
    private function trackedCentered(string $text, float $baseline, float $size, string $fontRole, array $color, float $tracking): void
    {
        $this->useFont($fontRole, $size);
        $this->pdf->SetTextColor($color[0], $color[1], $color[2]);

        $chars = mb_str_split($text);
        $total = 0.0;

        foreach ($chars as $char) {
            $total += $this->pdf->GetStringWidth($char) + $tracking;
        }

        $total -= $tracking;
        $x = $this->cx - $total / 2;

        foreach ($chars as $char) {
            $this->textAt($x, $baseline, $char);
            $x += $this->pdf->GetStringWidth($char) + $tracking;
        }
    }

    /**
     * Greedy word wrap measured with the given font role at the given size.
     *
     * @return list<string>
     */
    private function wrapLines(string $fontRole, string $text, float $size, float $maxWidth): array
    {
        $this->useFont($fontRole, $size);

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $trial = $current === '' ? $word : "{$current} {$word}";

            if ($current === '' || $this->pdf->GetStringWidth($trial) <= $maxWidth) {
                $current = $trial;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Draw a centred, wrapped paragraph starting at a baseline.
     *
     * @param  array{int, int, int}  $color
     */
    private function paragraphCentered(string $text, float $startBaseline, float $size, string $fontRole, array $color, float $maxWidth, float $leading): void
    {
        $baseline = $startBaseline;

        foreach ($this->wrapLines($fontRole, $text, $size, $maxWidth) as $line) {
            $this->centeredText($line, $baseline, $size, $fontRole, $color);
            $baseline -= $leading;
        }
    }

    /**
     * A symmetric 4-point sparkle (orientation-independent), centred at (cx, cy).
     *
     * @param  array{int, int, int}  $color
     */
    private function sparkle(float $cx, float $cy, float $scale, array $color): void
    {
        $centerY = $this->flipY($cy);
        $x = fn (float $u): float => $cx + $u * $scale;
        $y = fn (float $v): float => $centerY + $v * $scale;

        $this->pdf->Polycurve($x(0), $y(-6), [
            [$x(1), $y(-2), $x(2), $y(-1), $x(6), $y(0)],
            [$x(2), $y(1), $x(1), $y(2), $x(0), $y(6)],
            [$x(-1), $y(2), $x(-2), $y(1), $x(-6), $y(0)],
            [$x(-2), $y(-1), $x(-1), $y(-2), $x(0), $y(-6)],
        ], 'F', [], $color);
    }

    private function goldRule(float $centerY, float $width): void
    {
        $this->pdf->SetDrawColor(self::GOLD[0], self::GOLD[1], self::GOLD[2]);
        $this->pdf->SetLineWidth(1.2);
        $this->pdf->Line($this->cx - $width / 2, $this->flipY($centerY), $this->cx + $width / 2, $this->flipY($centerY));
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function filledEllipse(float $cx, float $cy, float $rx, float $ry, array $color): void
    {
        $this->pdf->Ellipse($cx, $this->flipY($cy), $rx, $ry, 0, 0, 360, 'F', [], $color);
    }

    /**
     * Small moon-and-star brand glyph centred at (cx, cy).
     *
     * @param  array{int, int, int}  $moonColor
     * @param  array{int, int, int}  $starColor
     */
    private function brandGlyph(float $cx, float $cy, float $r, array $moonColor, array $starColor): void
    {
        $this->filledEllipse($cx, $cy, $r, $r, $moonColor);
        $this->sparkle($cx + $r * 1.1, $cy + $r * 0.9, $r * 0.16, $starColor);
    }

    /**
     * Thin crop marks at the four trim corners, drawn in the bleed margin.
     * The home variant has no bleed and draws none.
     */
    private function drawCropMarks(): void
    {
        if (! $this->printMarks) {
            return;
        }

        $length = 6 * self::MM;
        $gap = 1.4 * self::MM;

        $this->pdf->SetDrawColor(self::CROP_MARK[0], self::CROP_MARK[1], self::CROP_MARK[2]);
        $this->pdf->SetLineWidth(0.4);

        $corners = [
            ['x' => $this->bleed, 'y' => $this->bleed, 'sx' => -1, 'sy' => -1],
            ['x' => $this->bleed + $this->trimW, 'y' => $this->bleed, 'sx' => 1, 'sy' => -1],
            ['x' => $this->bleed, 'y' => $this->bleed + $this->trimH, 'sx' => -1, 'sy' => 1],
            ['x' => $this->bleed + $this->trimW, 'y' => $this->bleed + $this->trimH, 'sx' => 1, 'sy' => 1],
        ];

        foreach ($corners as $corner) {
            $this->pdf->Line(
                $corner['x'],
                $this->flipY($corner['y'] + $corner['sy'] * $gap),
                $corner['x'],
                $this->flipY($corner['y'] + $corner['sy'] * ($gap + $length)),
            );
            $this->pdf->Line(
                $corner['x'] + $corner['sx'] * $gap,
                $this->flipY($corner['y']),
                $corner['x'] + $corner['sx'] * ($gap + $length),
                $this->flipY($corner['y']),
            );
        }
    }

    // ---------------------------------------------------------------- images

    /**
     * Locate an illustration on the public disk and measure it.
     *
     * @return array{path: string, width: int, height: int}|null
     */
    private function resolveImage(?string $relativePath): ?array
    {
        if ($relativePath === null || ! Storage::disk('public')->exists($relativePath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($relativePath);
        $size = @getimagesize($absolutePath);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return ['path' => $absolutePath, 'width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Downsample an illustration to 300 DPI at its placed size and transcode
     * it to JPEG, so the embedded bytes match what print can actually use.
     * Any failure falls back to embedding the original file untouched.
     *
     * @param  array{path: string, width: int, height: int}  $image
     * @return array{path: string, width: int, height: int}
     */
    private function placedImage(array $image, float $placedWPt, float $placedHPt): array
    {
        try {
            $targetW = max(1, min($image['width'], (int) ceil($placedWPt / 72 * self::TARGET_DPI)));
            $targetH = max(1, min($image['height'], (int) ceil($placedHPt / 72 * self::TARGET_DPI)));

            $source = @imagecreatefromstring((string) file_get_contents($image['path']));

            if ($source === false) {
                return $image;
            }

            $resampled = imagecreatetruecolor($targetW, $targetH);
            // JPEG has no alpha; flatten onto white first.
            $white = imagecolorallocate($resampled, 255, 255, 255);
            imagefill($resampled, 0, 0, (int) $white);
            imagecopyresampled($resampled, $source, 0, 0, 0, 0, $targetW, $targetH, $image['width'], $image['height']);
            imagedestroy($source);

            $tempPath = storage_path('app/pdf-tmp/'.Str::lower(Str::random(12)).'.jpg');
            File::ensureDirectoryExists(dirname($tempPath));

            if (! imagejpeg($resampled, $tempPath, self::JPEG_QUALITY)) {
                imagedestroy($resampled);

                return $image;
            }

            imagedestroy($resampled);
            $this->tempImages[] = $tempPath;

            return ['path' => $tempPath, 'width' => $targetW, 'height' => $targetH];
        } catch (Throwable $exception) {
            Log::warning('Storybook PDF: image transcode failed, embedding the original.', ['exception' => $exception->getMessage()]);

            return $image;
        }
    }

    /**
     * Draw an image scaled to cover a box whose (x, y) is its bottom-left
     * corner, clipped to the box. Alignment 'top' keeps the top edge (used for
     * the cover, whose title art lives near the top); 'center' centres.
     *
     * @param  array{path: string, width: int, height: int}  $image
     */
    private function drawCoverFit(array $image, float $x, float $y, float $w, float $h, string $align = 'center'): void
    {
        $scale = max($w / $image['width'], $h / $image['height']);
        $dw = $image['width'] * $scale;
        $dh = $image['height'] * $scale;
        $dx = $x + ($w - $dw) / 2;
        $dy = $align === 'top' ? $y + $h - $dh : $y + ($h - $dh) / 2;

        $placed = $this->placedImage($image, $dw, $dh);

        $this->pdf->StartTransform();
        $this->pdf->Rect($x, $this->flipY($y + $h), $w, $h, 'CNZ');
        $this->pdf->Image($placed['path'], $dx, $this->flipY($dy + $dh), $dw, $dh);
        $this->pdf->StopTransform();
    }

    /**
     * Draw an image scaled to fit WHOLLY inside a box whose (x, y) is its
     * bottom-left corner, centered on both axes - the page background shows
     * through on the short sides instead of the image being cropped.
     *
     * @param  array{path: string, width: int, height: int}  $image
     */
    private function drawContainFit(array $image, float $x, float $y, float $w, float $h): void
    {
        $scale = min($w / $image['width'], $h / $image['height']);
        $dw = $image['width'] * $scale;
        $dh = $image['height'] * $scale;
        $dx = $x + ($w - $dw) / 2;
        $dy = $y + ($h - $dh) / 2;

        $placed = $this->placedImage($image, $dw, $dh);

        $this->pdf->Image($placed['path'], $dx, $this->flipY($dy + $dh), $dw, $dh);
    }

    // ---------------------------------------------------------------- pages

    private function drawCoverPage(Book $book): void
    {
        $this->addLeaf();

        $cover = $this->resolveImage($book->cover_image_path);

        if ($cover !== null && $this->imageFit === 'full') {
            // Full-image mode: the whole cover art, centered on the night
            // sky so the letterbox reads as a deliberate matte. Overlay mode
            // bleeds the cover edge to edge like crop mode.
            $this->verticalGradient(0, 0, $this->pageW, $this->pageH, self::INDIGO_MID, self::INK_NIGHT);
            $this->drawContainFit($cover, 0, 0, $this->pageW, $this->pageH);
        } elseif ($cover !== null) {
            // The AI cover carries its title art near the top; keep the top
            // edge when the art is cropped into the page.
            $this->drawCoverFit($cover, 0, 0, $this->pageW, $this->pageH, 'top');
        } else {
            $this->verticalGradient(0, 0, $this->pageW, $this->pageH, self::INDIGO_MID, self::INK_NIGHT);
            $this->filledEllipse($this->bleed + $this->trimW * 0.72, $this->topY(120), 30, 30, self::MOON);

            $frameInset = self::MARGIN * 0.7;
            $this->strokeRect(
                $this->bleed + $frameInset,
                $this->bleed + $frameInset,
                $this->trimW - 2 * $frameInset,
                $this->trimH - 2 * $frameInset,
                self::GOLD,
                1.6,
                0.6,
            );

            $this->trackedCentered('CUBFABLE', $this->topY(96), 12, 'accent', self::GOLD, 3);
            $this->centeredText($book->child_name, $this->topY($this->trimH / 2 - 16), 44, 'display', self::CREAM);
            $this->centeredText('a bedtime adventure', $this->topY($this->trimH / 2 + 28), 19, 'italic', self::GOLD_SOFT);
        }

        $this->drawCropMarks();
    }

    private function drawTitlePage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $this->trackedCentered('A CUBFABLE KEEPSAKE', $this->topY(96), 11, 'accent', self::GOLD, 3.2);
        $this->centeredText($book->child_name, $this->topY($this->trimH / 2 - 24), 46, 'display', self::INK);
        $this->centeredText("and the {$book->theme}", $this->topY($this->trimH / 2 + 18), 21, 'italic', self::INDIGO);
        $this->goldRule($this->topY($this->trimH / 2 + 56), 60);
        $this->centeredText("written for {$book->child_name}, with love", $this->topY($this->trimH / 2 + 86), 13, 'body', self::MUTED);

        $this->brandGlyph($this->cx - 34, $this->topY($this->trimH - 72), 8, self::INDIGO, self::GOLD);
        $this->useFont('accent', 15);
        $this->pdf->SetTextColor(self::INDIGO[0], self::INDIGO[1], self::INDIGO[2]);
        $this->textAt($this->cx - 18, $this->topY($this->trimH - 68), 'CubFable');

        $this->drawCropMarks();
    }

    /**
     * Imprint / copyright leaf: the quiet page a printed book carries.
     */
    private function drawImprintPage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $year = now()->year;
        $bottom = self::MARGIN + 60;

        $lines = [
            "\u{00A9} {$year} CubFable. All rights reserved.",
            "Printed for {$book->child_name}.",
            "Illustrated in the {$book->art_style} style - story language: {$book->language}.",
            "CubFable keepsake edition - book no. {$book->id}.",
            'cubfable.com',
        ];

        $baseline = $this->bleed + $bottom + (count($lines) - 1) * 18;

        foreach ($lines as $line) {
            $this->centeredText($line, $baseline, 10.5, 'body', self::MUTED);
            $baseline -= 18;
        }

        $this->drawCropMarks();
    }

    private function drawDedicationPage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $this->sparkle($this->cx, $this->topY($this->trimH / 2 - 64), 1.4, self::GOLD);
        $this->goldRule($this->topY($this->trimH / 2 - 36), 44);
        $this->centeredText("For {$book->child_name},", $this->topY($this->trimH / 2 + 6), 25, 'italic', self::INDIGO);
        $this->centeredText('with all our love.', $this->topY($this->trimH / 2 + 38), 25, 'italic', self::INDIGO);
        $this->goldRule($this->topY($this->trimH / 2 + 70), 44);

        $this->drawCropMarks();
    }

    private function drawStoryPage(Page $page): void
    {
        $this->addLeaf();

        $image = $this->resolveImage($page->image_path);

        // Overlay mode: the illustration IS the page and the story text sits
        // on a translucent panel over it.
        if ($image !== null && $this->imageFit === 'overlay') {
            $this->drawOverlayStoryPage($page, $image);

            return;
        }

        $this->fillPage(self::PAPER);

        // A full-width art band across the top (bleeding off both sides on the
        // print variant), story text centred in the zone beneath, folio at the
        // bottom - the classic square picture-book spread.
        $bandW = $this->pageW;
        $bandH = min($this->pageW * (2 / 3), $this->pageH * 0.62);

        // Full-image mode: the band hugs the illustration's own ratio, so
        // landscape art spans edge to edge with nothing cropped and portrait
        // art gets the tallest band the text zone allows (letterboxed on the
        // sides only). The art also earns more of the page than in crop mode.
        if ($image !== null && $this->imageFit === 'full' && $image['width'] > 0) {
            $bandH = min($this->pageH * 0.72, $bandW * $image['height'] / $image['width']);
        }

        $bandBottom = $this->pageH - $bandH;

        if ($image !== null) {
            // Full-image mode shows the whole illustration inside the band
            // (paper showing on the short sides); crop mode fills the band.
            if ($this->imageFit === 'full') {
                $this->drawContainFit($image, 0, $bandBottom, $bandW, $bandH);
            } else {
                $this->drawCoverFit($image, 0, $bandBottom, $bandW, $bandH);
            }

            // A hairline gold seam grounds the band on the page.
            $this->pdf->SetDrawColor(self::GOLD[0], self::GOLD[1], self::GOLD[2]);
            $this->pdf->SetLineWidth(1);
            $this->pdf->setAlpha(0.55);
            $this->pdf->Line(0, $this->flipY($bandBottom), $this->pageW, $this->flipY($bandBottom));
            $this->pdf->setAlpha(1);
        } else {
            $this->fillRect(0, $bandBottom, $bandW, $bandH, self::LILAC);
            $this->centeredText('illustration coming soon', $bandBottom + $bandH / 2, 14, 'italic', self::MUTED);
        }

        // Text zone between the band and the folio, sized to fit.
        $text = $this->normalizeArabicMarks(trim((string) $page->text));
        $maxTextW = $this->trimW - 2 * self::MARGIN - 20;
        $zoneTop = $bandBottom - 34;
        $zoneBottom = $this->bleed + 58;
        $zoneH = $zoneTop - $zoneBottom;

        $size = 16.0;
        $lines = [];

        if ($text !== '') {
            do {
                $lines = $this->wrapLines('story', $text, $size, $maxTextW);
                $textH = count($lines) * $size * 1.45;

                if ($textH <= $zoneH || $size <= 11) {
                    break;
                }

                $size -= 0.5;
            } while (true);
        }

        $leading = $size * 1.45;
        $textH = count($lines) * $leading;
        $firstBaseline = $zoneTop - ($zoneH - $textH) / 2 - $size;

        if ($this->rtl) {
            $this->pdf->setRTL(true);
        }

        $baseline = $firstBaseline;

        foreach ($lines as $line) {
            if ($this->rtl) {
                $this->rtlCenteredStoryLine($line, $baseline, $size);
            } else {
                $this->centeredText($line, $baseline, $size, 'story', self::INK);
            }

            $baseline -= $leading;
        }

        if ($this->rtl) {
            $this->pdf->setRTL(false);
        }

        $this->trackedCentered((string) $page->page_number, $this->bleed + 30, 11, 'accent', self::MUTED, 2);
        $this->drawCropMarks();
    }

    /**
     * The overlay story page: the illustration bleeds edge to edge across
     * the whole page (the short sides are cropped slightly when the ratio
     * differs, never letterboxed), and the story text sits over its lower
     * part on a rounded translucent night panel - like a modern picture-book
     * plate.
     *
     * @param  array{path: string, width: int, height: int}  $image
     */
    private function drawOverlayStoryPage(Page $page, array $image): void
    {
        $this->drawCoverFit($image, 0, 0, $this->pageW, $this->pageH);

        $text = $this->normalizeArabicMarks(trim((string) $page->text));

        if ($text !== '') {
            $padX = 28.0;
            $padY = 18.0;
            $maxTextW = $this->trimW - 2 * self::MARGIN - 2 * $padX;

            $size = 15.0;
            $lines = [];

            do {
                $lines = $this->wrapLines('story', $text, $size, $maxTextW);

                if (count($lines) <= 6 || $size <= 11) {
                    break;
                }

                $size -= 0.5;
            } while (true);

            // The panel hugs its longest line instead of spanning the page:
            // a short sentence gets a neat plate, not a floating gray bar.
            $this->useFont('story', $size);
            $maxLineW = 0.0;

            foreach ($lines as $line) {
                $maxLineW = max($maxLineW, (float) $this->pdf->GetStringWidth($line));
            }

            $panelW = min($this->trimW - 2 * self::MARGIN, max(200.0, $maxLineW + 2 * $padX));

            // Vertical centering built from the glyph run itself: ascent
            // covers Arabic diacritics above the baseline, descent the tails
            // below, so the gap over the first line equals the gap under the
            // last one.
            $leading = $size * 1.5;
            $ascent = $size * 1.05;
            $descent = $size * 0.35;
            $textH = (count($lines) - 1) * $leading + $ascent + $descent;
            $panelH = $textH + 2 * $padY;
            $panelBottom = $this->bleed + 56;
            $panelX = $this->cx - $panelW / 2;
            $panelTop = $this->flipY($panelBottom + $panelH);
            $radius = 14.0;

            // A soft halo, a near-opaque night plate (busy art must never
            // muddy the words), and the brand's gold hairline frame.
            $this->pdf->SetFillColor(self::INK_NIGHT[0], self::INK_NIGHT[1], self::INK_NIGHT[2]);
            $this->pdf->setAlpha(0.16);
            $this->pdf->RoundedRect($panelX - 4, $panelTop - 4, $panelW + 8, $panelH + 8, $radius + 3, '1111', 'F');
            $this->pdf->setAlpha(0.82);
            $this->pdf->RoundedRect($panelX, $panelTop, $panelW, $panelH, $radius, '1111', 'F');
            $this->pdf->setAlpha(0.85);
            $this->pdf->SetDrawColor(self::GOLD[0], self::GOLD[1], self::GOLD[2]);
            $this->pdf->SetLineWidth(0.9);
            $this->pdf->RoundedRect($panelX, $panelTop, $panelW, $panelH, $radius, '1111', 'D');
            $this->pdf->setAlpha(1);

            if ($this->rtl) {
                $this->pdf->setRTL(true);
            }

            $baseline = $panelBottom + $panelH - $padY - $ascent;

            foreach ($lines as $line) {
                if ($this->rtl) {
                    $this->rtlCenteredStoryLine($line, $baseline, $size, self::CREAM);
                } else {
                    $this->centeredText($line, $baseline, $size, 'story', self::CREAM);
                }

                $baseline -= $leading;
            }

            if ($this->rtl) {
                $this->pdf->setRTL(false);
            }
        }

        $this->trackedCentered((string) $page->page_number, $this->bleed + 30, 11, 'accent', self::PERIWINKLE, 2);
        $this->drawCropMarks();
    }

    /**
     * An RTL story line drawn through TCPDF's own centered cell: the library
     * shapes, bidi-reorders and centers the glyph run itself, so manual
     * centering math (measured on the unshaped logical string) can never
     * disagree with what actually lands on the page.
     *
     * Coordinates are MIRRORED while setRTL(true) is active: SetX stores
     * `w - x`, and Cell grows leftward from that stored right edge. Passing
     * the bleed as x therefore pins the cell's right edge to the trim's
     * right edge and the full-trim cell covers the page exactly; passing a
     * physical left-space x instead pushes the whole line off-canvas.
     */
    /**
     * @param  array{int, int, int}|null  $color
     */
    private function rtlCenteredStoryLine(string $text, float $baseline, float $size, ?array $color = null): void
    {
        $color ??= self::INK;

        $this->useFont('story', $size);
        $this->pdf->SetTextColor($color[0], $color[1], $color[2]);
        $this->pdf->SetXY($this->bleed, $this->flipY($baseline));
        // calign 'L' anchors the cell at the font BASELINE, exactly like the
        // LTR textAt() primitive; the default 'T' treats y as the top of a
        // line-height cell, which sinks the glyphs almost a full line lower
        // than every baseline computed by the layout math.
        $this->pdf->Cell($this->trimW, 0, $text, 0, 0, 'C', false, '', 0, false, 'L');
    }

    private function drawEndPage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $this->sparkle($this->cx, $this->topY($this->trimH / 2 - 54), 1.7, self::GOLD);
        $this->centeredText('The End', $this->topY($this->trimH / 2), 38, 'display', self::INDIGO);
        $this->goldRule($this->topY($this->trimH / 2 + 32), 50);
        $this->trackedCentered('A CUBFABLE KEEPSAKE', $this->topY($this->trimH / 2 + 64), 11, 'accent', self::GOLD, 3.2);
        $this->centeredText("Made just for {$book->child_name}.", $this->topY($this->trimH / 2 + 98), 13, 'italic', self::MUTED);

        $this->drawCropMarks();
    }

    private function drawBackCover(Book $book): void
    {
        $this->addLeaf();
        $this->verticalGradient(0, 0, $this->pageW, $this->pageH, self::INDIGO_MID, self::INK_NIGHT);

        // a few fixed stars
        $stars = [
            [0.15, 0.2, 1.4], [0.82, 0.16, 1.1], [0.3, 0.34, 0.9], [0.7, 0.4, 1.2],
            [0.2, 0.7, 1.0], [0.85, 0.66, 1.3], [0.5, 0.8, 0.9], [0.62, 0.24, 0.8],
        ];

        foreach ($stars as [$fx, $fy, $radius]) {
            $this->filledEllipse($this->bleed + $this->trimW * $fx, $this->bleed + $this->trimH * $fy, $radius, $radius, self::STARLIGHT);
        }

        $this->brandGlyph($this->cx - 30, $this->topY($this->trimH / 2 - 36), 9, self::MOON, self::GOLD);
        $this->useFont('accent', 17);
        $this->pdf->SetTextColor(self::CREAM[0], self::CREAM[1], self::CREAM[2]);
        $this->textAt($this->cx - 8, $this->topY($this->trimH / 2 - 32), 'CubFable');

        $this->paragraphCentered(
            'Every child deserves to be the hero of their own story.',
            $this->topY($this->trimH / 2 + 20),
            17,
            'italic',
            self::GOLD_SOFT,
            $this->trimW - 2 * self::MARGIN - 40,
            23,
        );

        $this->trackedCentered('MADE FOR '.Str::upper($book->child_name), $this->topY($this->trimH - 90), 10, 'accent', self::PERIWINKLE, 2.6);

        $this->drawCropMarks();
    }
}
