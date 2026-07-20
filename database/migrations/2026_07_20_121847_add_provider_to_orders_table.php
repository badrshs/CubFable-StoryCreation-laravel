<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders now remember which payment provider they were created with so
     * reconciliation can always talk to the right provider, even after the
     * admin switches the active one. The Stripe-specific id column becomes
     * provider-agnostic (Stripe pi_... and Paddle txn_... ids never collide,
     * so the unique index stays sufficient).
     *
     * Each step is guarded because the feature/mobile-app branch ships its
     * own make_orders_provider_agnostic migration: whichever runs first wins
     * and the other becomes a no-op, so any merge order is safe.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'provider')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('provider')->default('stripe')->after('book_id');
            });
        }

        if (! Schema::hasColumn('orders', 'provider_transaction_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->renameColumn('stripe_payment_intent_id', 'provider_transaction_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'provider_transaction_id') && ! Schema::hasColumn('orders', 'stripe_payment_intent_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->renameColumn('provider_transaction_id', 'stripe_payment_intent_id');
            });
        }

        if (Schema::hasColumn('orders', 'provider')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('provider');
            });
        }
    }
};
