<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transaction_classification_rules', function (Blueprint $table) {
            $table->dropUnique('txn_class_rules_user_pattern_class_origin_unique');
        });

        Schema::table('transaction_classification_rules', function (Blueprint $table) {
            $table->string('match_mode')->default('description')->after('origin');
            $table->decimal('amount', 12, 2)->nullable()->after('match_mode');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_classification_rules', function (Blueprint $table) {
            $table->dropColumn(['match_mode', 'amount']);
        });

        Schema::table('transaction_classification_rules', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'normalized_pattern', 'classification', 'origin'],
                'txn_class_rules_user_pattern_class_origin_unique',
            );
        });
    }
};
