<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_payment_intent_id')->unique();
            $table->unsignedInteger('amount');
            $table->string('currency')->default('eur');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // At most ONE pending order per book, so concurrent checkouts cannot
        // mint duplicate PaymentIntents. Laravel's Blueprint has no fluent
        // partial index; this raw statement works on SQLite and Postgres.
        DB::statement("CREATE UNIQUE INDEX orders_one_pending_per_book ON orders (book_id) WHERE status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_one_pending_per_book');

        Schema::dropIfExists('orders');
    }
};
