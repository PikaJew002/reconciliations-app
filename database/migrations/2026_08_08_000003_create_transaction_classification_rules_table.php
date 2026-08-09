<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaction_classification_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('normalized_pattern');

            $table->string('classification');

            $table->string('direction')->default('credit');

            $table->string('origin');

            $table->boolean('is_active')->default(true);

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(
                ['user_id', 'normalized_pattern', 'classification', 'origin'],
                'txn_class_rules_user_pattern_class_origin_unique',
            );
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_classification_rules');
    }
};
