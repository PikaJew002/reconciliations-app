<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaction_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_transaction_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('order_component_id')
                ->constrained()
                ->cascadeOnDelete();

            // Signed amount allocated from the transaction
            $table->decimal('allocated_amount', 10, 2);

            // Automatic reconciliation vs manual adjustment
            $table->enum('allocation_type', [
                'automatic',
                'manual',
                'imported',
            ])->default('automatic');

            $table->decimal('match_confidence', 5, 2)
                ->nullable();

            $table->text('notes')
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->index('bank_transaction_id');
            $table->index('order_component_id');
            $table->index('allocation_type');

            $table->unique([
                'bank_transaction_id',
                'order_component_id',
            ], 'transaction_allocations_composite_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_allocations');
    }
};
