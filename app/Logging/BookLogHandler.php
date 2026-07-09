<?php

namespace App\Logging;

use Illuminate\Support\Facades\Context;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * Tees every log record that belongs to a book into that book's own file
 * (storage/logs/books/book-{id}.log), so one file tells a generation's
 * story A to Z. The book id comes from the record's own context or from
 * the shared Context (set once at the top of jobs, and
 * propagated into queued jobs automatically). Records without a book id
 * pass through untouched; the normal channels keep logging everything.
 */
class BookLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        $bookId = $record->context['book_id'] ?? Context::get('book_id');

        if (! is_numeric($bookId) || (int) $bookId < 1) {
            return;
        }

        $path = storage_path('logs/books/book-'.(int) $bookId.'.log');

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        @file_put_contents($path, (string) $record->formatted, FILE_APPEND | LOCK_EX);
    }

    protected function getDefaultFormatter(): FormatterInterface
    {
        // Laravel's "[time] channel.LEVEL:" shape, so the admin log viewer
        // parses book files into structured, level-filterable entries.
        return new LineFormatter(
            "[%datetime%] book.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
        );
    }
}
