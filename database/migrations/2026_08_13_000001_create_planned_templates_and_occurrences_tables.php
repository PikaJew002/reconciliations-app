<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained('merchants')
                ->nullOnDelete();

            $table->string('name');
            $table->string('classification');
            $table->string('match_mode');
            $table->string('normalized_pattern')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedTinyInteger('expected_day');
            $table->decimal('expected_amount', 12, 2);
            $table->unsignedSmallInteger('lookback_days')->default(7);
            $table->unsignedSmallInteger('lookforward_days')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });

        Schema::create('planned_occurrences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('template_id')
                ->nullable()
                ->constrained('planned_templates')
                ->cascadeOnDelete();

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained('merchants')
                ->nullOnDelete();

            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->unique()
                ->constrained('bank_transactions')
                ->nullOnDelete();

            $table->string('classification');
            $table->string('match_mode');
            $table->string('normalized_pattern')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->date('expected_date');
            $table->decimal('expected_amount', 12, 2);
            $table->unsignedSmallInteger('lookback_days')->default(7);
            $table->unsignedSmallInteger('lookforward_days')->default(3);
            $table->string('status')->default('planned');
            $table->timestamps();

            $table->unique(['template_id', 'expected_date']);
            $table->index(['user_id', 'status', 'expected_date']);
            $table->index(['user_id', 'classification', 'expected_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_occurrences');
        Schema::dropIfExists('planned_templates');
    }
};
