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
        $book = Book::factory()->complete()->create([
            'child_name' => 'Luna',
            'theme' => 'Moonlit Forest',
            'cover_image_path' => 'books/1/cover-test.png',
        ]);

        $this->storePng('books/1/cover-test.png', 40, 60);

        foreach ([1, 2, 3] as $number) {
            $this->storePng("books/1/pages/{$number}-test.png", 60, 40);

            Page::factory()->for($book)->complete()->create([
                'page_number' => $number,
                'image_path' => "books/1/pages/{$number}-test.png",
            ]);
        }

        $pdf = app(StorybookPdfBuilder::class)->build($book);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
        // cover + half-title + dedication + 3 story pages + The End + back cover
        $this->assertSame(8, preg_match_all('#/Type /Page\b#', $pdf));
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
        // cover + half-title + dedication + 2 story pages + The End + back cover
        $this->assertSame(7, preg_match_all('#/Type /Page\b#', $pdf));
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
