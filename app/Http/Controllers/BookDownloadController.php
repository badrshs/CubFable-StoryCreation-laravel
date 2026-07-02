<?php

namespace App\Http\Controllers;

use App\Services\Pdf\StorybookPdfBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookDownloadController extends Controller
{
    /**
     * Compose the print-ready storybook PDF on the server and stream it back
     * as an attachment. Owner-scoped: a foreign book id is a 404.
     */
    public function __invoke(Request $request, StorybookPdfBuilder $builder, int $id): StreamedResponse
    {
        $book = $request->user()->books()->findOrFail($id);

        $pdfBytes = $builder->build($book);

        $slug = Str::slug($book->child_name);
        $filename = ($slug === '' ? 'storybook' : $slug).'-cubfable-storybook.pdf';

        return response()->streamDownload(function () use ($pdfBytes): void {
            echo $pdfBytes;
        }, $filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store',
        ]);
    }
}
