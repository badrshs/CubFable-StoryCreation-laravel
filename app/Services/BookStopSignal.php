<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The admin's stop button for a running generation pipeline. The signal is a
 * short-lived cache flag: the worker checks it between images and halts
 * before generating the next one (the image in flight still finishes). Every
 * new run clears the flag at its start, so a stale stop can never kill the
 * next intentional run.
 */
class BookStopSignal
{
    private const TTL_MINUTES = 60;

    public function request(int $bookId): void
    {
        Cache::put($this->key($bookId), true, now()->addMinutes(self::TTL_MINUTES));
    }

    public function clear(int $bookId): void
    {
        Cache::forget($this->key($bookId));
    }

    public function requested(int $bookId): bool
    {
        return (bool) Cache::get($this->key($bookId), false);
    }

    private function key(int $bookId): string
    {
        return "book-stop.{$bookId}";
    }
}
