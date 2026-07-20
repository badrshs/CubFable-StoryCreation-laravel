<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
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
        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'provider' => PaymentProvider::Stripe,
            'provider_transaction_id' => 'pi_'.Str::random(24),
            'amount' => 799,
            'currency' => 'eur',
            'status' => OrderStatus::Pending,
            'paid_at' => null,
        ];
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
     * An order created through Paddle.
     */
    public function paddle(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => PaymentProvider::Paddle,
            'provider_transaction_id' => 'txn_'.Str::random(24),
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
