<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Read-only window into storage/logs for the admin: pick a file, filter by
 * level, search, expand stack traces, download or clear. Only the tail of
 * large files is parsed so a runaway multi-hundred-MB log cannot take the
 * page down.
 */
class LogController extends Controller
{
    /**
     * How much of the end of a file is parsed (2 MB).
     */
    private const int TAIL_BYTES = 2 * 1024 * 1024;

    private const int MAX_ENTRIES = 300;

    public function index(Request $request): Response
    {
        $files = $this->files();
        $selected = (string) $request->query('file', $files[0]['name'] ?? '');

        $this->guardFilename($selected, $files);

        $level = (string) $request->query('level', '');
        $search = trim((string) $request->query('search', ''));

        ['entries' => $entries, 'truncated' => $truncated] = $selected === ''
            ? ['entries' => [], 'truncated' => false]
            : $this->parse(storage_path('logs/'.$selected));

        $counts = array_count_values(array_column($entries, 'level'));

        if ($level !== '') {
            $entries = array_values(array_filter($entries, fn (array $entry): bool => $entry['level'] === $level));
        }

        if ($search !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => stripos($entry['message'].' '.$entry['context'], $search) !== false,
            ));
        }

        return Inertia::render('admin/logs', [
            'files' => $files,
            'selected' => $selected,
            'entries' => array_slice($entries, 0, self::MAX_ENTRIES),
            'counts' => $counts,
            'truncated' => $truncated,
            'filters' => ['level' => $level, 'search' => $search],
        ]);
    }

    public function download(Request $request): BinaryFileResponse
    {
        $file = (string) $request->query('file', '');
        $this->guardFilename($file, $this->files());

        abort_if($file === '', 404);

        return response()->download(storage_path('logs/'.$file));
    }

    /**
     * Empty a log file (keeps the file so running processes keep their handle).
     */
    public function clear(Request $request): RedirectResponse
    {
        $file = (string) $request->query('file', '');
        $this->guardFilename($file, $this->files());

        abort_if($file === '', 404);

        File::put(storage_path('logs/'.$file), '');

        return back();
    }

    /**
     * Every .log file in storage/logs plus the per-book logs in
     * storage/logs/books (listed as "books/book-N.log"), newest first.
     *
     * @return list<array{name: string, size: string, modified: string}>
     */
    private function files(): array
    {
        $paths = [
            ...File::glob(storage_path('logs/*.log')),
            ...File::glob(storage_path('logs/books/*.log')),
        ];

        $files = collect($paths)
            ->map(fn (string $path): array => [
                'name' => str_contains(str_replace('\\', '/', $path), '/logs/books/')
                    ? 'books/'.basename($path)
                    : basename($path),
                'size' => $this->humanSize((int) File::size($path)),
                'modified' => date('Y-m-d H:i', (int) File::lastModified($path)),
                'mtime' => (int) File::lastModified($path),
            ])
            ->sortByDesc('mtime')
            ->values();

        return array_values($files->map(fn (array $file): array => [
            'name' => $file['name'],
            'size' => $file['size'],
            'modified' => $file['modified'],
        ])->all());
    }

    /**
     * A filename must be one of the listed log files - no traversal, ever.
     *
     * @param  list<array{name: string, size: string, modified: string}>  $files
     */
    private function guardFilename(string $file, array $files): void
    {
        if ($file === '') {
            return;
        }

        if (! in_array($file, array_column($files, 'name'), true)) {
            throw ValidationException::withMessages(['file' => 'Unknown log file.']);
        }
    }

    /**
     * Parse the tail of a log file into entries, newest first. Laravel-format
     * lines become structured entries with level and expandable context; files
     * in other formats (browser logs) fall back to raw lines.
     *
     * @return array{entries: list<array{time: string, env: string, level: string, message: string, context: string}>, truncated: bool}
     */
    private function parse(string $path): array
    {
        if (! File::exists($path)) {
            return ['entries' => [], 'truncated' => false];
        }

        $size = (int) File::size($path);
        $truncated = $size > self::TAIL_BYTES;

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['entries' => [], 'truncated' => false];
        }

        if ($truncated) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }

        $content = (string) stream_get_contents($handle);
        fclose($handle);

        $pattern = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2})?)\] (\w+)\.(\w+): /m';

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);

        $entries = [];

        if ($matches[0] !== []) {
            $count = count($matches[0]);

            for ($i = 0; $i < $count; $i++) {
                $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
                $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($content);
                $body = trim(substr($content, $start, $end - $start));

                $newline = strpos($body, "\n");
                $message = $newline === false ? $body : substr($body, 0, $newline);
                $context = $newline === false ? '' : trim(substr($body, $newline + 1));

                $entries[] = [
                    'time' => $matches[1][$i][0],
                    'env' => $matches[2][$i][0],
                    'level' => strtolower($matches[3][$i][0]),
                    'message' => mb_substr($message, 0, 2000),
                    'context' => mb_substr($context, 0, 8000),
                ];
            }
        } else {
            // Not a Laravel-format log: show the raw tail, line by line.
            $lines = array_slice(array_filter(explode("\n", $content), fn (string $line): bool => trim($line) !== ''), -self::MAX_ENTRIES);

            foreach ($lines as $line) {
                $entries[] = [
                    'time' => '',
                    'env' => '',
                    'level' => 'raw',
                    'message' => mb_substr($line, 0, 2000),
                    'context' => '',
                ];
            }
        }

        return ['entries' => array_reverse($entries), 'truncated' => $truncated];
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
