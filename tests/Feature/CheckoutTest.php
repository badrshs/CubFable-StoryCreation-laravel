<?php

namespace Tests\Feature;

use App\Enums\BookStatus;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Models\Book;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_renders_with_the_payment_intent_props(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) use ($book): void {
            $mock->shouldReceive('createOrReusePaymentIntent')
                ->once()
                ->withArgs(fn (Book $candidate): bool => $candidate->is($book))
                ->andReturn([
                    'clientSecret' => 'pi_test_secret_abc',
                    'publishableKey' => 'pk_test_key',
                    'amount' => 799,
                    'currency' => 'eur',
                ]);
        });

        $this->withoutVite();

        $this->actingAs($user)
            ->get(route('checkout.show', ['id' => $book->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('checkout')
                ->where('book.id', $book->id)
                ->where('book.status', 'draft')
                ->where('clientSecret', 'pi_test_secret_abc')
                ->where('publishableKey', 'pk_test_key')
                ->where('amount', '7.99')
                ->where('currency', 'EUR'));
    }

    public function test_a_book_no_longer_awaiting_payment_redirects_to_the_reader(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->complete()->for($user)->create();

        $this->mock(StripePaymentService::class)->shouldNotReceive('createOrReusePaymentIntent');

        $this->actingAs($user)
            ->get(route('checkout.show', ['id' => $book->id]))
            ->assertRedirect(route('books.show', ['id' => $book->id]));
    }

    public function test_an_already_completed_payment_redirects_to_the_reader(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createOrReusePaymentIntent')
                ->once()
                ->andThrow(new PaymentAlreadyCompletedException);
        });

        $this->actingAs($user)
            ->get(route('checkout.show', ['id' => $book->id]))
            ->assertRedirect(route('books.show', ['id' => $book->id]));
    }

    public function test_reconcile_returns_the_book_status_as_plain_json(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) use ($book): void {
            $mock->shouldReceive('reconcile')
                ->once()
                ->withArgs(fn (Book $candidate): bool => $candidate->is($book))
                ->andReturn(BookStatus::Pending);
        });

        $this->actingAs($user)
            ->post(route('checkout.reconcile', ['id' => $book->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['status' => 'pending']);
    }
}
