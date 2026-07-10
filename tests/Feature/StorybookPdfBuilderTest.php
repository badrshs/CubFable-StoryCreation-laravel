<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Page;
use App\Services\Pdf\StorybookPdfBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorybookPdfBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_builds_a_print_ready_pdf_for_a_fully_illustrated_book(): void
    {
        $book = $this->illustratedBook();

        $pdf = app(StorybookPdfBuilder::class)->build($book);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        // cover + half-title + imprint + dedication + 3 story pages + The End + back cover
        $this->assertSame(9, preg_match_all('#/Type /Page\b#', $pdf));

        // Print variant: square trim plus 3mm bleed on each side, with the
        // TrimBox inset by the bleed.
        $this->assertStringContainsString('612.2834', $pdf);
        $this->assertStringContainsString('/TrimBox [8.503937', $pdf);
        // Illustrations are transcoded to JPEG (DCTDecode) before embedding.
        $this->assertStringContainsString('/DCTDecode', $pdf);
    }

    public function test_the_home_variant_is_trim_only(): void
    {
        $book = $this->illustratedBook();

        $pdf = app(StorybookPdfBuilder::class)->build($book, 'home');

        $this->assertStringStartsWith('%PDF', $pdf);
        // No bleed: the page IS the square trim, and no inset trim exists.
        $this->assertStringContainsString('595.2755', $pdf);
        $this->assertStringNotContainsString('/TrimBox [8.503937', $pdf);
    }

    public function test_a4_portrait_pages_follow_the_preset_dimensions(): void
    {
        $book = $this->illustratedBook();

        $pdf = app(StorybookPdfBuilder::class)->build($book, 'home', 'a4-portrait');

        // 210 x 297 mm in points.
        $this->assertStringContainsString('595.2755', $pdf);
        $this->assertStringContainsString('841.8897', $pdf);
    }

    public function test_the_configured_page_size_drives_the_default_build(): void
    {
        config()->set('cubfable.pdf.page_size', 'a4-landscape');

        $pdf = app(StorybookPdfBuilder::class)->build($this->illustratedBook(), 'home');

        // 297 mm wide, 210 mm tall.
        $this->assertStringContainsString('841.8897', $pdf);
        $this->assertStringContainsString('595.2755', $pdf);
        $this->assertMatchesRegularExpression('/MediaBox \[0\.000000 0\.000000 841\.8897\d+ 595\.2755\d+\]/', $pdf);
    }

    public function test_a5_pages_follow_the_preset_dimensions(): void
    {
        $pdf = app(StorybookPdfBuilder::class)->build($this->illustratedBook(), 'home', 'a5-portrait');

        // 148 x 210 mm in points.
        $this->assertMatchesRegularExpression('/MediaBox \[0\.000000 0\.000000 419\.5275\d+ 595\.2755\d+\]/', $pdf);
    }

    public function test_full_image_fit_letterboxes_instead_of_cropping(): void
    {
        $book = $this->illustratedBook();

        $cropped = app(StorybookPdfBuilder::class)->build($book, 'home', 'square-210', 'crop');
        $full = app(StorybookPdfBuilder::class)->build($book, 'home', 'square-210', 'full');

        $this->assertStringStartsWith('%PDF', $full);
        $this->assertSame(9, preg_match_all('#/Type /Page\b#', $full));

        // The 40x60 (2:3) cover on the 595pt square page: crop mode scales
        // it to fill the width (595 x 892, top/bottom cropped), full mode
        // fits the whole height (396 x 595, sides letterboxed). The image
        // transform matrix carries those numbers.
        $this->assertMatchesRegularExpression('/q 595\.2\d+ 0 0 892\.9\d+/', $this->uncompressedStreams($cropped));
        $this->assertMatchesRegularExpression('/q 396\.8\d+ 0 0 595\.2\d+/', $this->uncompressedStreams($full));
    }

    public function test_overlay_fit_draws_the_image_full_page_with_a_text_panel(): void
    {
        $pdf = app(StorybookPdfBuilder::class)->build($this->illustratedBook(), 'home', 'a4-portrait', 'overlay');

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(9, preg_match_all('#/Type /Page\b#', $pdf));

        $streams = $this->uncompressedStreams($pdf);

        // The 60x40 (3:2) page art bleeds edge to edge on A4 portrait: it
        // fills the 841pt height (1262pt wide, sides cropped), never
        // letterboxed.
        $this->assertMatchesRegularExpression('/q 1262\.8\d+ 0 0 841\.8\d+/', $streams);
        // The translucent text panel is a rounded rect (curves + fill).
        $this->assertStringContainsString(' c', $streams);
        $this->assertMatchesRegularExpression('/ re f| f\n/', $streams);
    }

    public function test_the_configured_image_fit_drives_the_default_build(): void
    {
        config()->set('cubfable.pdf.image_fit', 'full');

        $pdf = app(StorybookPdfBuilder::class)->build($this->illustratedBook(), 'home', 'square-210');

        $this->assertMatchesRegularExpression('/q 396\.8\d+ 0 0 595\.2\d+/', $this->uncompressedStreams($pdf));
    }

    /**
     * Every gz-compressed content stream of the PDF, concatenated.
     */
    private function uncompressedStreams(string $pdf): string
    {
        $out = '';

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);

            if ($content !== false) {
                $out .= $content;
            }
        }

        return $out;
    }

    public function test_an_unknown_size_key_falls_back_to_the_square_default(): void
    {
        $pdf = app(StorybookPdfBuilder::class)->build($this->illustratedBook(), 'home', 'no-such-size');

        $this->assertMatchesRegularExpression('/MediaBox \[0\.000000 0\.000000 595\.2755\d+ 595\.2755\d+\]/', $pdf);
    }

    public function test_an_arabic_book_builds_with_a_script_capable_font(): void
    {
        $book = $this->illustratedBook(['language' => 'ar']);
        $book->pages()->update(['text' => 'وجدت ليلى فانوسًا مضيئًا في الغابة.']);

        $pdf = app(StorybookPdfBuilder::class)->build($book);

        $this->assertStringStartsWith('%PDF', $pdf);
        // Amiri is embedded for Arabic-script story text.
        $this->assertStringContainsString('Amiri', $pdf);
    }

    public function test_arabic_story_text_lands_on_the_page_not_off_canvas(): void
    {
        $book = $this->illustratedBook(['language' => 'ar']);
        $book->pages()->update(['text' => 'وجدت ليلى فانوسًا مضيئًا في الغابة.']);

        $pdf = app(StorybookPdfBuilder::class)->build($book, 'home');

        // TCPDF mirrors x-coordinates in RTL mode; a wrong origin puts the
        // whole story line outside the canvas (text exists in the stream but
        // is invisible). Every text operator must start inside the page.
        $xPositions = $this->textOperatorXPositions($pdf);

        $this->assertNotEmpty($xPositions);
        $this->assertGreaterThanOrEqual(0, min($xPositions), 'text drawn off-canvas (negative x)');
        $this->assertLessThanOrEqual(596, max($xPositions), 'text drawn beyond the page width');
    }

    /**
     * The x position of every text-showing operator across all content
     * streams of the PDF.
     *
     * @return list<float>
     */
    private function textOperatorXPositions(string $pdf): array
    {
        $xPositions = [];

        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        foreach ($streams[1] as $stream) {
            $content = @gzuncompress($stream);

            if ($content === false) {
                continue; // image/font streams
            }

            preg_match_all('/BT (-?\d+\.\d+) (-?\d+\.\d+) Td/', $content, $ops);

            foreach ($ops[1] as $x) {
                $xPositions[] = (float) $x;
            }
        }

        return $xPositions;
    }

    public function test_it_builds_with_gradient_fallbacks_when_images_are_missing(): void
    {
        $book = Book::factory()->pending()->create([
            'child_name' => 'Zoe',
            'cover_image_path' => null,
        ]);

        Page::factory()->for($book)->create([
            'page_number' => 1,
            'image_path' => null,
        ]);

        // An image path whose file never landed on disk must also fall back.
        Page::factory()->for($book)->create([
            'page_number' => 2,
            'image_path' => 'books/9/pages/2-missing.png',
        ]);

        $pdf = app(StorybookPdfBuilder::class)->build($book);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        // cover + half-title + imprint + dedication + 2 story pages + The End + back cover
        $this->assertSame(8, preg_match_all('#/Type /Page\b#', $pdf));
    }

    /**
     * A complete book with a real (tiny) cover and three page illustrations.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function illustratedBook(array $attributes = []): Book
    {
        $book = Book::factory()->complete()->create([
            'child_name' => 'Luna',
            'theme' => 'Moonlit Forest',
            'font' => 'classic',
            'cover_image_path' => 'books/1/cover-test.png',
            ...$attributes,
        ]);

        $this->storePng('books/1/cover-test.png', 40, 60);

        foreach ([1, 2, 3] as $number) {
            $this->storePng("books/1/pages/{$number}-test.png", 60, 40);

            Page::factory()->for($book)->complete()->create([
                'page_number' => $number,
                'image_path' => "books/1/pages/{$number}-test.png",
            ]);
        }

        return $book;
    }

    /**
     * Write a tiny real PNG to the fake public disk.
     */
    private function storePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 75, 63, 160));

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);
    }
}
