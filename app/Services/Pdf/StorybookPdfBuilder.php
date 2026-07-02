<?php

namespace App\Services\Pdf;

use App\Models\Book;
use App\Models\Page;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use TCPDF;
use TCPDF_FONTS;
use Throwable;

/**
 * Print-ready storybook PDF, composed programmatically (no HTML).
 *
 * - Trim: A4 portrait, with 3mm bleed on every side and crop marks, so a print
 *   shop can impose and trim it with no prepress fixes.
 * - Proper PDF boxes (MediaBox / BleedBox / TrimBox / ArtBox) are set on every
 *   page. Fonts are embedded; illustrations are embedded at full resolution.
 * - Layout reads like a real picture book: cover, half-title, dedication, one
 *   composed spread per page, "The End", and a back cover.
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
     * A4 trim width in points.
     */
    private const float TRIM_W = 595.28;

    /**
     * A4 trim height in points.
     */
    private const float TRIM_H = 841.89;

    private const float BLEED = 3 * self::MM;

    private const float PAGE_W = self::TRIM_W + 2 * self::BLEED;

    private const float PAGE_H = self::TRIM_H + 2 * self::BLEED;

    /**
     * Safe margin inside the trim.
     */
    private const float MARGIN = 15 * self::MM;

    /**
     * Horizontal centre of the trim, in media coordinates.
     */
    private const float CX = self::BLEED + self::TRIM_W / 2;

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

    /** @var array{int, int, int} */
    private const array SHADOW = [33, 27, 58];

    /** @var array{int, int, int} back-cover stars */
    private const array STARLIGHT = [217, 224, 255];

    /** @var array{int, int, int} missing-illustration placeholder */
    private const array LILAC = [237, 232, 247];

    /** @var array{int, int, int} back-cover eyebrow text */
    private const array PERIWINKLE = [191, 199, 242];

    /** @var array{int, int, int} */
    private const array CROP_MARK = [26, 26, 31];

    /** @var array<string, string> layout role => bundled TTF file */
    private const array FONT_FILES = [
        'title' => 'Cormorant-Bold.ttf',
        'semi' => 'Cormorant-SemiBold.ttf',
        'body' => 'Cormorant-Medium.ttf',
        'italic' => 'Cormorant-Italic.ttf',
        'accent' => 'Baloo2-SemiBold.ttf',
    ];

    /** @var array<string, array{family: string, style: string, file: string}> core font stand-ins used when a TTF conversion fails */
    private const array CORE_FONT_FALLBACKS = [
        'title' => ['family' => 'times', 'style' => 'B', 'file' => ''],
        'semi' => ['family' => 'times', 'style' => 'B', 'file' => ''],
        'body' => ['family' => 'times', 'style' => '', 'file' => ''],
        'italic' => ['family' => 'times', 'style' => 'I', 'file' => ''],
        'accent' => ['family' => 'helvetica', 'style' => 'B', 'file' => ''],
    ];

    private TCPDF $pdf;

    /** @var array<string, array{family: string, style: string, file: string}> */
    private array $fonts = [];

    /**
     * Compose the complete storybook and return the raw PDF bytes.
     */
    public function build(Book $book): string
    {
        $this->pdf = $this->makeDocument($book);
        $this->fonts = $this->resolveFonts();

        $this->drawCoverPage($book);
        $this->drawTitlePage($book);
        $this->drawDedicationPage($book);

        foreach ($book->pages()->orderBy('page_number')->get() as $page) {
            $this->drawStoryPage($page);
        }

        $this->drawEndPage($book);
        $this->drawBackCover($book);

        return $this->pdf->Output('', 'S');
    }

    private function makeDocument(Book $book): TCPDF
    {
        $pdf = new TCPDF('P', 'pt', [self::PAGE_W, self::PAGE_H], true, 'UTF-8', false);
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
     * role to a TCPDF font definition.
     *
     * @return array<string, array{family: string, style: string, file: string}>
     */
    private function resolveFonts(): array
    {
        $cacheDir = storage_path('app/tcpdf-fonts/');
        File::ensureDirectoryExists($cacheDir);

        $fonts = [];

        foreach (self::FONT_FILES as $role => $ttf) {
            $fonts[$role] = $this->convertTtf($ttf, $cacheDir) ?? self::CORE_FONT_FALLBACKS[$role];
        }

        return $fonts;
    }

    /**
     * @return array{family: string, style: string, file: string}|null
     */
    private function convertTtf(string $ttf, string $cacheDir): ?array
    {
        try {
            $family = TCPDF_FONTS::addTTFfont(resource_path('fonts/'.$ttf), 'TrueTypeUnicode', '', 96, $cacheDir);
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
     * Add a leaf carrying the full set of print boxes.
     */
    private function addLeaf(): void
    {
        $this->pdf->AddPage('P', [
            'MediaBox' => ['llx' => 0, 'lly' => 0, 'urx' => self::PAGE_W, 'ury' => self::PAGE_H],
            'BleedBox' => ['llx' => 0, 'lly' => 0, 'urx' => self::PAGE_W, 'ury' => self::PAGE_H],
            'TrimBox' => [
                'llx' => self::BLEED,
                'lly' => self::BLEED,
                'urx' => self::BLEED + self::TRIM_W,
                'ury' => self::BLEED + self::TRIM_H,
            ],
            'ArtBox' => [
                'llx' => self::BLEED + self::MARGIN,
                'lly' => self::BLEED + self::MARGIN,
                'urx' => self::BLEED + self::TRIM_W - self::MARGIN,
                'ury' => self::BLEED + self::TRIM_H - self::MARGIN,
            ],
        ]);
    }

    /**
     * Convert a distance down from the trim's top edge into a bottom-left y.
     */
    private function topY(float $fromTop): float
    {
        return self::BLEED + self::TRIM_H - $fromTop;
    }

    /**
     * Flip a bottom-left y coordinate into TCPDF's top-left space.
     */
    private function flipY(float $y): float
    {
        return self::PAGE_H - $y;
    }

    /**
     * @param  array{int, int, int}  $color
     */
    private function fillPage(array $color): void
    {
        $this->fillRect(0, 0, self::PAGE_W, self::PAGE_H, $color);
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
        $this->textAt(self::CX - $width / 2, $baseline, $text);
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
        $x = self::CX - $total / 2;

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
        $this->pdf->Line(self::CX - $width / 2, $this->flipY($centerY), self::CX + $width / 2, $this->flipY($centerY));
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
     */
    private function drawCropMarks(): void
    {
        $length = 6 * self::MM;
        $gap = 1.4 * self::MM;

        $this->pdf->SetDrawColor(self::CROP_MARK[0], self::CROP_MARK[1], self::CROP_MARK[2]);
        $this->pdf->SetLineWidth(0.4);

        $corners = [
            ['x' => self::BLEED, 'y' => self::BLEED, 'sx' => -1, 'sy' => -1],
            ['x' => self::BLEED + self::TRIM_W, 'y' => self::BLEED, 'sx' => 1, 'sy' => -1],
            ['x' => self::BLEED, 'y' => self::BLEED + self::TRIM_H, 'sx' => -1, 'sy' => 1],
            ['x' => self::BLEED + self::TRIM_W, 'y' => self::BLEED + self::TRIM_H, 'sx' => 1, 'sy' => 1],
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
     * Draw an image scaled to cover a box whose (x, y) is its bottom-left
     * corner, centred and clipped to the box.
     *
     * @param  array{path: string, width: int, height: int}  $image
     */
    private function drawCoverFit(array $image, float $x, float $y, float $w, float $h): void
    {
        $scale = max($w / $image['width'], $h / $image['height']);
        $dw = $image['width'] * $scale;
        $dh = $image['height'] * $scale;
        $dx = $x + ($w - $dw) / 2;
        $dy = $y + ($h - $dh) / 2;

        $this->pdf->StartTransform();
        $this->pdf->Rect($x, $this->flipY($y + $h), $w, $h, 'CNZ');
        $this->pdf->Image($image['path'], $dx, $this->flipY($dy + $dh), $dw, $dh);
        $this->pdf->StopTransform();
    }

    // ---------------------------------------------------------------- pages

    private function drawCoverPage(Book $book): void
    {
        $this->addLeaf();

        $cover = $this->resolveImage($book->cover_image_path);

        if ($cover !== null) {
            // The AI cover already carries the title art; run it full-bleed.
            $this->drawCoverFit($cover, 0, 0, self::PAGE_W, self::PAGE_H);
        } else {
            $this->verticalGradient(0, 0, self::PAGE_W, self::PAGE_H, self::INDIGO_MID, self::INK_NIGHT);
            $this->filledEllipse(self::BLEED + self::TRIM_W * 0.72, $this->topY(150), 34, 34, self::MOON);

            $frameInset = self::MARGIN * 0.7;
            $this->strokeRect(
                self::BLEED + $frameInset,
                self::BLEED + $frameInset,
                self::TRIM_W - 2 * $frameInset,
                self::TRIM_H - 2 * $frameInset,
                self::GOLD,
                1.6,
                0.6,
            );

            $this->trackedCentered('CUBFABLE', $this->topY(120), 12, 'accent', self::GOLD, 3);
            $this->centeredText($book->child_name, $this->topY(self::TRIM_H / 2 - 20), 46, 'title', self::CREAM);
            $this->centeredText('a bedtime adventure', $this->topY(self::TRIM_H / 2 + 26), 20, 'italic', self::GOLD_SOFT);
        }

        $this->drawCropMarks();
    }

    private function drawTitlePage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $this->trackedCentered('A CUBFABLE KEEPSAKE', $this->topY(150), 11, 'accent', self::GOLD, 3.2);
        $this->centeredText($book->child_name, $this->topY(self::TRIM_H / 2 - 30), 52, 'title', self::INK);
        $this->centeredText("and the {$book->theme}", $this->topY(self::TRIM_H / 2 + 16), 22, 'italic', self::INDIGO);
        $this->goldRule($this->topY(self::TRIM_H / 2 + 60), 60);
        $this->centeredText("written for {$book->child_name}, with love", $this->topY(self::TRIM_H / 2 + 92), 14, 'body', self::MUTED);

        $this->brandGlyph(self::CX - 34, $this->topY(self::TRIM_H - 96), 8, self::INDIGO, self::GOLD);
        $this->useFont('accent', 15);
        $this->pdf->SetTextColor(self::INDIGO[0], self::INDIGO[1], self::INDIGO[2]);
        $this->textAt(self::CX - 18, $this->topY(self::TRIM_H - 92), 'CubFable');

        $this->drawCropMarks();
    }

    private function drawDedicationPage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $this->sparkle(self::CX, $this->topY(self::TRIM_H / 2 - 70), 1.4, self::GOLD);
        $this->goldRule($this->topY(self::TRIM_H / 2 - 40), 44);
        $this->centeredText("For {$book->child_name},", $this->topY(self::TRIM_H / 2 + 6), 26, 'italic', self::INDIGO);
        $this->centeredText('with all our love.', $this->topY(self::TRIM_H / 2 + 40), 26, 'italic', self::INDIGO);
        $this->goldRule($this->topY(self::TRIM_H / 2 + 74), 44);

        $this->drawCropMarks();
    }

    private function drawStoryPage(Page $page): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        // The composition (illustration -> flourish -> story text) is measured,
        // then centred vertically in the page's content area so it reads well
        // for both portrait and landscape illustrations, with no dead space.
        $image = $this->resolveImage($page->image_path);

        $maxImageW = self::TRIM_W - 2 * self::MARGIN;
        $maxImageH = self::TRIM_H * 0.56;
        $contentTop = $this->topY(54);
        $contentBottom = self::BLEED + 70; // reserve for the folio
        $availableH = $contentTop - $contentBottom;

        $dw = $maxImageW;
        $dh = $maxImageW * (2 / 3);

        if ($image !== null) {
            $scale = min($maxImageW / $image['width'], $maxImageH / $image['height']);
            $dw = $image['width'] * $scale;
            $dh = $image['height'] * $scale;
        }

        $text = trim((string) $page->text);
        $size = 16.5;
        $leading = $size * 1.5;
        $lines = $text === '' ? [] : $this->wrapLines('body', $text, $size, $maxImageW - 40);
        $textH = count($lines) * $leading;

        $sparkleGap = 30;
        $textTopGap = 26;
        $blockH = $dh + $sparkleGap + $textTopGap + $textH;

        $blockTop = $contentTop - max(0, ($availableH - $blockH) / 2);
        $imageX = self::CX - $dw / 2;
        $imageY = $blockTop - $dh;

        if ($image !== null) {
            $this->fillRect($imageX + 4, $imageY - 4, $dw, $dh, self::SHADOW, 0.14);
            $this->pdf->Image($image['path'], $imageX, $this->flipY($imageY + $dh), $dw, $dh);
            $this->strokeRect($imageX, $imageY, $dw, $dh, self::GOLD, 1, 0.55);
        } else {
            $this->fillRect($imageX, $imageY, $dw, $dh, self::LILAC);
            $this->centeredText('illustration coming soon', $imageY + $dh / 2, 14, 'italic', self::MUTED);
        }

        $this->sparkle(self::CX, $imageY - $sparkleGap * 0.5, 1.1, self::GOLD);

        $baseline = $imageY - $sparkleGap - $textTopGap;

        foreach ($lines as $line) {
            $this->centeredText($line, $baseline, $size, 'body', self::INK);
            $baseline -= $leading;
        }

        $this->trackedCentered((string) $page->page_number, self::BLEED + 34, 11, 'accent', self::MUTED, 2);
        $this->drawCropMarks();
    }

    private function drawEndPage(Book $book): void
    {
        $this->addLeaf();
        $this->fillPage(self::PAPER);

        $this->sparkle(self::CX, $this->topY(self::TRIM_H / 2 - 58), 1.7, self::GOLD);
        $this->centeredText('The End', $this->topY(self::TRIM_H / 2), 40, 'title', self::INDIGO);
        $this->goldRule($this->topY(self::TRIM_H / 2 + 34), 50);
        $this->trackedCentered('A CUBFABLE KEEPSAKE', $this->topY(self::TRIM_H / 2 + 68), 11, 'accent', self::GOLD, 3.2);
        $this->centeredText("Made just for {$book->child_name}.", $this->topY(self::TRIM_H / 2 + 104), 14, 'italic', self::MUTED);

        $this->drawCropMarks();
    }

    private function drawBackCover(Book $book): void
    {
        $this->addLeaf();
        $this->verticalGradient(0, 0, self::PAGE_W, self::PAGE_H, self::INDIGO_MID, self::INK_NIGHT);

        // a few fixed stars
        $stars = [
            [0.15, 0.2, 1.4], [0.82, 0.16, 1.1], [0.3, 0.34, 0.9], [0.7, 0.4, 1.2],
            [0.2, 0.7, 1.0], [0.85, 0.66, 1.3], [0.5, 0.8, 0.9], [0.62, 0.24, 0.8],
        ];

        foreach ($stars as [$fx, $fy, $radius]) {
            $this->filledEllipse(self::BLEED + self::TRIM_W * $fx, self::BLEED + self::TRIM_H * $fy, $radius, $radius, self::STARLIGHT);
        }

        $this->brandGlyph(self::CX - 30, $this->topY(self::TRIM_H / 2 - 40), 9, self::MOON, self::GOLD);
        $this->useFont('accent', 17);
        $this->pdf->SetTextColor(self::CREAM[0], self::CREAM[1], self::CREAM[2]);
        $this->textAt(self::CX - 8, $this->topY(self::TRIM_H / 2 - 36), 'CubFable');

        $this->paragraphCentered(
            'Every child deserves to be the hero of their own story.',
            $this->topY(self::TRIM_H / 2 + 20),
            18,
            'italic',
            self::GOLD_SOFT,
            self::TRIM_W - 2 * self::MARGIN - 40,
            24,
        );

        $this->trackedCentered('MADE FOR '.Str::upper($book->child_name), $this->topY(self::TRIM_H - 120), 10, 'accent', self::PERIWINKLE, 2.6);

        $this->drawCropMarks();
    }
}
