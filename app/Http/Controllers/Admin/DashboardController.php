<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\Order;
use App\Models\Page;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The owner's overview: money in, AI money out, production health.
     */
    public function __invoke(): Response
    {
        $paidOrders = Order::query()->where('status', OrderStatus::Paid);

        $revenueCents = (int) $paidOrders->clone()->sum('amount');
        $currency = (string) ($paidOrders->clone()->latest('id')->value('currency') ?? config('cubfable.price_currency'));
        $aiSpend = (float) AiUsage::query()->sum('cost_usd');

        $byStatus = Book::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $completedBooks = (int) ($byStatus[BookStatus::Complete->value] ?? 0);

        // Fourteen day production trend. Two measures on different scales
        // (books vs dollars) are deliberately two separate charts.
        $since = now()->subDays(13)->startOfDay();

        $booksPerDay = Book::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $spendPerDay = AiUsage::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, sum(cost_usd) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $trend = collect(range(0, 13))->map(function (int $offset) use ($since, $booksPerDay, $spendPerDay): array {
            $day = $since->addDays($offset)->toDateString();

            return [
                'day' => $day,
                'books' => (int) ($booksPerDay[$day] ?? 0),
                'spend' => round((float) ($spendPerDay[$day] ?? 0), 2),
            ];
        })->all();

        $spendByModel = AiUsage::query()
            ->selectRaw('model, sum(cost_usd) as total, count(*) as calls')
            ->groupBy('model')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn (AiUsage $row): array => [
                'model' => $row->model,
                'spend' => round((float) $row->getAttribute('total'), 2),
                'calls' => (int) $row->getAttribute('calls'),
            ])
            ->all();

        $spendByKind = AiUsage::query()
            ->selectRaw('kind, sum(cost_usd) as total')
            ->groupBy('kind')
            ->orderByDesc('total')
            ->pluck('total', 'kind')
            ->map(fn ($total): float => round((float) $total, 2))
            ->all();

        $topStyles = Book::query()
            ->selectRaw('art_style, count(*) as total')
            ->groupBy('art_style')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'art_style')
            ->all();

        $recentFailures = Book::query()
            ->where('status', BookStatus::Failed)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'child_name', 'updated_at'])
            ->map(fn (Book $book): array => [
                'id' => $book->id,
                'childName' => $book->child_name,
                'failedAt' => $book->updated_at?->toDateTimeString() ?? '',
            ])
            ->all();

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'revenue' => round($revenueCents / 100, 2),
                'currency' => strtoupper($currency),
                'aiSpend' => round($aiSpend, 2),
                'booksTotal' => (int) $byStatus->sum(),
                'avgCostPerBook' => $completedBooks > 0 ? round($aiSpend / $completedBooks, 2) : 0.0,
                'failedJobs' => (int) DB::table('failed_jobs')->count(),
                'flaggedForReview' => Page::query()->whereNotNull('flagged_at')->count()
                    + Book::query()->whereNotNull('cover_flagged_at')->count(),
            ],
            'byStatus' => $byStatus->all(),
            'trend' => $trend,
            'spendByModel' => $spendByModel,
            'spendByKind' => $spendByKind,
            'topStyles' => $topStyles,
            'recentFailures' => $recentFailures,
        ]);
    }
}
