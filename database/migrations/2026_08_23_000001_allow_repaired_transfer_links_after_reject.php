<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaction_transfer_links', function (Blueprint $table) {
            $table->index('debit_transaction_id', 'transaction_transfer_links_debit_id_index');
            $table->index('credit_transaction_id', 'transaction_transfer_links_credit_id_index');
            $table->dropUnique(['debit_transaction_id']);
            $table->dropUnique(['credit_transaction_id']);
            $table->unique(
                ['debit_transaction_id', 'credit_transaction_id'],
                'transaction_transfer_links_pair_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('transaction_transfer_links', function (Blueprint $table) {
            $table->dropUnique('transaction_transfer_links_pair_unique');
            $table->dropIndex('transaction_transfer_links_debit_id_index');
            $table->dropIndex('transaction_transfer_links_credit_id_index');
            $table->unique('debit_transaction_id');
            $table->unique('credit_transaction_id');
        });
    }
};
