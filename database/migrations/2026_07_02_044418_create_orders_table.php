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
        // partial index, so we express it per driver. SQLite and Postgres
        // support real partial indexes; MySQL has no partial index, so we use
        // a functional unique index over a CASE expression that yields the
        // book_id only for pending rows and NULL otherwise (MySQL treats NULLs
        // as distinct in a unique index, so non-pending rows never collide).
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("CREATE UNIQUE INDEX orders_one_pending_per_book ON orders ((CASE WHEN status = 'pending' THEN book_id END))");
        } else {
            DB::statement("CREATE UNIQUE INDEX orders_one_pending_per_book ON orders (book_id) WHERE status = 'pending'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            if (Schema::hasTable('orders')) {
                DB::statement('DROP INDEX orders_one_pending_per_book ON orders');
            }
        } else {
            DB::statement('DROP INDEX IF EXISTS orders_one_pending_per_book');
        }

        Schema::dropIfExists('orders');
    }
};
