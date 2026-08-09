<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaction_transfer_links', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('debit_transaction_id')
                ->constrained('bank_transactions')
                ->cascadeOnDelete();

            $table->foreignId('credit_transaction_id')
                ->constrained('bank_transactions')
                ->cascadeOnDelete();

            $table->uuid('transfer_group_id');

            $table->decimal('match_confidence', 5, 2)->nullable();

            $table->string('status')->default('suggested');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('debit_transaction_id');
            $table->unique('credit_transaction_id');
            $table->index('user_id');
            $table->index('transfer_group_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_transfer_links');
    }
};
