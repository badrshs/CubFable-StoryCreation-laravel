<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orders were Stripe-only; mobile purchases arrive through RevenueCat. The
 * Stripe intent id becomes nullable and every order gains a provider plus a
 * provider-agnostic transaction id (the idempotency key for webhooks).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('provider')->default('stripe');
            $table->string('provider_transaction_id')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable()->change();
        });

        DB::table('orders')
            ->whereNull('provider_transaction_id')
            ->update(['provider_transaction_id' => DB::raw('stripe_payment_intent_id')]);

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('provider_transaction_id');
        });

        // SQLite rewrites the whole table for a column change and recreates
        // the one-pending-per-book index WITHOUT its partial WHERE clause,
        // silently turning it into "one order per book, ever". Rebuild the
        // real partial index explicitly.
        DB::statement('DROP INDEX IF EXISTS orders_one_pending_per_book');
        DB::statement("CREATE UNIQUE INDEX orders_one_pending_per_book ON orders (book_id) WHERE status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['provider_transaction_id']);
            $table->dropColumn(['provider', 'provider_transaction_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable(false)->change();
        });

        DB::statement('DROP INDEX IF EXISTS orders_one_pending_per_book');
        DB::statement("CREATE UNIQUE INDEX orders_one_pending_per_book ON orders (book_id) WHERE status = 'pending'");
    }
};
