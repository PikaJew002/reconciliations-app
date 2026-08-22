<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_spends', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('account_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('source');
            $table->timestamp('spent_at');
            $table->decimal('amount', 10, 2);
            $table->string('card_last_four', 4)->nullable();
            $table->string('classification');
            $table->string('status')->default('pending');
            $table->string('review_reason')->nullable();

            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('venmo_activity_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'spent_at']);
            $table->index(['user_id', 'account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_spends');
    }
};
