<?php

namespace Tests\Feature\Admin;

use App\Enums\BookStatus;
use App\Enums\OrderStatus;
use App\Models\AiUsage;
use App\Models\Book;
use App\Models\Order;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_aggregates_revenue_spend_and_statuses(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $template = Template::factory()->create();

        $complete = Book::factory()->complete()->for($admin)->for($template)->create();
        Book::factory()->for($admin)->for($template)->create(['status' => BookStatus::Failed]);

        Order::query()->create([
            'user_id' => $admin->id,
            'book_id' => $complete->id,
            'stripe_payment_intent_id' => 'pi_test_1',
            'amount' => 799,
            'currency' => 'eur',
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);

        AiUsage::query()->create([
            'book_id' => $complete->id,
            'kind' => 'image',
            'provider' => 'openrouter',
            'model' => 'x-ai/grok-imagine-image-quality',
            'prompt_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cost_usd' => 0.5,
            'estimated' => false,
        ]);
        AiUsage::query()->create([
            'book_id' => $complete->id,
            'kind' => 'text',
            'provider' => 'openrouter',
            'model' => 'deepseek/deepseek-v4-pro',
            'prompt_tokens' => 100,
            'output_tokens' => 200,
            'total_tokens' => 300,
            'cost_usd' => 0.01,
            'estimated' => false,
        ]);

        $this->withoutVite()
            ->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dashboard')
                ->where('stats.revenue', 7.99)
                ->where('stats.currency', 'EUR')
                ->where('stats.aiSpend', 0.51)
                ->where('stats.booksTotal', 2)
                ->where('byStatus.complete', 1)
                ->where('byStatus.failed', 1)
                ->has('trend', 14)
                ->has('spendByModel', 2)
                ->where('spendByModel.0.model', 'x-ai/grok-imagine-image-quality')
                ->where('spendByKind.image', 0.5)
                ->has('recentFailures', 1));
    }
}
