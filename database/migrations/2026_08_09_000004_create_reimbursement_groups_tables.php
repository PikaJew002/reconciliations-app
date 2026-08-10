<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reimbursement_groups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name')->nullable();
            $table->text('notes')->nullable();

            $table->string('status')->default('open');

            $table->foreignId('remainder_category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->string('remainder_classification')->nullable();

            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status'], 'reimb_groups_user_status_index');
        });

        Schema::create('reimbursement_group_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reimbursement_group_id')
                ->constrained('reimbursement_groups')
                ->cascadeOnDelete();

            $table->foreignId('bank_transaction_id')
                ->constrained('bank_transactions')
                ->cascadeOnDelete();

            $table->string('role');

            $table->decimal('amount', 12, 2);

            $table->json('prior_state')->nullable();

            $table->timestamps();

            $table->unique('bank_transaction_id', 'reimb_group_tx_bank_txn_unique');
            $table->index(['reimbursement_group_id', 'role'], 'reimb_group_tx_group_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reimbursement_group_transactions');
        Schema::dropIfExists('reimbursement_groups');
    }
};
