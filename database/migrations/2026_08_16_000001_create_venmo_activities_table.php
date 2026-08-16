<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venmo_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('import_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('external_id');

            $table->timestamp('occurred_at');

            $table->string('type');
            $table->string('status')->nullable();

            $table->string('note')->nullable();
            $table->string('from_name')->nullable();
            $table->string('to_name')->nullable();

            $table->decimal('amount', 10, 2);
            $table->decimal('fee', 10, 2)->nullable();

            $table->string('funding_source')->nullable();
            $table->string('destination')->nullable();
            $table->string('funding_last_four', 4)->nullable();
            $table->string('destination_last_four', 4)->nullable();

            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->constrained('bank_transactions')
                ->nullOnDelete();

            $table->foreignId('cashed_out_by_activity_id')
                ->nullable()
                ->constrained('venmo_activities')
                ->nullOnDelete();

            $table->string('match_status')->default('unmatched');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'external_id']);
            $table->index(['user_id', 'match_status']);
            $table->index('bank_transaction_id');
            $table->index('cashed_out_by_activity_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venmo_activities');
    }
};
