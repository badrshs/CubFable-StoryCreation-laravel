<?php

namespace App\Http\Controllers;

use App\Enums\BookStatus;
use App\Models\Book;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the marketing home page. Deliberately exposes only non-identifying
     * aggregate counts, never book rows or per-user content.
     */
    public function __invoke(): Response
    {
        return Inertia::render('home', [
            'stats' => [
                'totalBooks' => Book::query()->count(),
                'completedBooks' => Book::query()->where('status', BookStatus::Complete)->count(),
            ],
        ]);
    }
}
