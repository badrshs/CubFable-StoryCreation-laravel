<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $paymentIntentId = 'pi_'.Str::random(24);

        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'provider' => Order::PROVIDER_STRIPE,
            'provider_transaction_id' => $paymentIntentId,
            'stripe_payment_intent_id' => $paymentIntentId,
            'amount' => 799,
            'currency' => 'eur',
            'status' => OrderStatus::Pending,
            'paid_at' => null,
        ];
    }

    /**
     * An in-app purchase order handled through RevenueCat. The transaction id
     * is stamped only once the store purchase is seen (webhook or reconcile).
     */
    public function revenuecat(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => Order::PROVIDER_REVENUECAT,
            'provider_transaction_id' => null,
            'stripe_payment_intent_id' => null,
        ]);
    }

    /**
     * An order awaiting payment confirmation.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Pending,
            'paid_at' => null,
        ]);
    }

    /**
     * A confirmed, paid order.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }
}
