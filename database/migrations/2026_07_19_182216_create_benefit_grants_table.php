<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('benefit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('benefit', 64);
            $table->string('device_id', 36)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'benefit']);
            $table->index(['benefit', 'device_id']);
            $table->index(['benefit', 'fingerprint']);
            $table->index(['benefit', 'ip']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benefit_grants');
    }
};
