<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_rejects_a_wrong_password()
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withToken($token)->deleteJson(route('api.v1.account.destroy'), [
            'password' => 'not-my-password',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_removes_user_tokens_and_owned_records()
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $character = Character::factory()->for($user)->create();
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withToken($token)->deleteJson(route('api.v1.account.destroy'), [
            'password' => 'password',
        ]);

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_deleted_users_token_no_longer_authenticates()
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->deleteJson(route('api.v1.account.destroy'), [
            'password' => 'password',
        ])->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson(route('api.v1.me.show'))->assertUnauthorized();
    }
}
