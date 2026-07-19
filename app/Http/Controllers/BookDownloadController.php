<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FindsAccessibleBooks;
use App\Services\Pdf\StorybookPdfBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookDownloadController extends Controller
{
    use FindsAccessibleBooks;

    /**
     * Compose the storybook PDF on the server and stream it back as an
     * attachment. Owner-scoped (admins may download any book). Two variants:
     * print (bleed + crop marks, for a print shop) and home (trim only).
     */
    public function __invoke(Request $request, StorybookPdfBuilder $builder, int $id): StreamedResponse
    {
        $book = $this->accessibleBook($request, $id);

        $variant = $request->query('variant') === 'home' ? 'home' : 'print';

        $pdfBytes = $builder->build($book, $variant);

        $slug = Str::slug($book->child_name);
        $filename = ($slug === '' ? 'storybook' : $slug)."-cubfable-storybook-{$variant}.pdf";

        return response()->streamDownload(function () use ($pdfBytes): void {
            echo $pdfBytes;
        }, $filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store',
        ]);
    }
}
