<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('import_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('account_id')
                ->constrained()
                ->cascadeOnDelete();

            // Null until the transaction is matched to a merchant.
            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Bank supplied identifier if available
            $table->string('external_id')->nullable();

            // Posted date from the bank
            $table->date('posted_at');

            // Transaction date (may differ from posted date)
            $table->date('transaction_date')->nullable();

            // Original bank description
            $table->string('description');

            // Cleaned description used for matching
            $table->string('normalized_description')->nullable();

            // Debit = negative, Credit = positive
            $table->decimal('amount', 10, 2);

            $table->string('currency', 3)
                ->default('USD');

            $table->enum('status', [
                'unmatched',
                'partial',
                'matched',
                'ignored',
            ])->default('unmatched');

            // User notes
            $table->text('notes')->nullable();

            // Original CSV row, import metadata, etc.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('posted_at');
            $table->index('transaction_date');
            $table->index('merchant_id');
            $table->index('status');
            $table->index('amount');

            $table->unique([
                'account_id',
                'external_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
