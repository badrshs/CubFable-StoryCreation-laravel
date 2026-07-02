<?php

namespace Tests\Feature;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\Page;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class BookOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_users_book_is_not_found_on_every_book_route(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $book = Book::factory()->complete()->for($owner)->create();
        $page = Page::factory()->for($book)->complete()->create([
            'page_number' => 1,
            'text' => 'Original text.',
        ]);

        $this->actingAs(User::factory()->create());

        $this->get(route('books.show', ['id' => $book->id]))->assertNotFound();
        $this->get(route('books.download', ['id' => $book->id]))->assertNotFound();
        $this->patch(route('pages.update', ['id' => $book->id, 'pageId' => $page->id]), [
            'text' => 'Hijacked.',
        ])->assertNotFound();
        $this->post(route('pages.regenerate', ['id' => $book->id, 'pageId' => $page->id]))->assertNotFound();
        $this->post(route('books.regenerate-cover', ['id' => $book->id]))->assertNotFound();

        $page->refresh();
        $this->assertSame('Original text.', $page->text);
        $this->assertSame(PageStatus::Complete, $page->status);
        $this->assertNull($book->refresh()->cover_status);
        Queue::assertNothingPushed();
    }

    public function test_another_users_draft_book_is_not_found_on_checkout(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->draft()->for($owner)->create();

        $this->mock(StripePaymentService::class)->shouldNotReceive('createOrReusePaymentIntent');

        $this->actingAs(User::factory()->create())
            ->get(route('checkout.show', ['id' => $book->id]))
            ->assertNotFound();
    }

    public function test_another_users_book_is_not_found_on_reconcile(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->draft()->for($owner)->create();

        $this->mock(StripePaymentService::class)->shouldNotReceive('reconcile');

        $this->actingAs(User::factory()->create())
            ->post(route('checkout.reconcile', ['id' => $book->id]))
            ->assertNotFound();
    }

    public function test_owners_can_check_out_their_own_draft_book(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->draft()->for($user)->create();

        $this->mock(StripePaymentService::class, function (MockInterface $mock) use ($book): void {
            $mock->shouldReceive('createOrReusePaymentIntent')
                ->once()
                ->withArgs(fn (Book $candidate): bool => $candidate->is($book))
                ->andReturn([
                    'clientSecret' => 'pi_test_secret_123',
                    'publishableKey' => 'pk_test_123',
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
                ->where('book.id', $book->id));
    }
}
