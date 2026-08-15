<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('planned_occurrences', 'bills_customized')) {
            Schema::table('planned_occurrences', function (Blueprint $table) {
                $table->boolean('bills_customized')->default(false)->after('status');
            });
        }

        if (! Schema::hasTable('planned_template_bills')) {
            Schema::create('planned_template_bills', function (Blueprint $table) {
                $table->id();

                $table->foreignId('planned_template_id')
                    ->constrained('planned_templates')
                    ->cascadeOnDelete();

                $table->foreignId('category_id')
                    ->constrained('categories')
                    ->restrictOnDelete();

                $table->decimal('expected_amount', 12, 2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('planned_occurrence_bills')) {
            Schema::create('planned_occurrence_bills', function (Blueprint $table) {
                $table->id();

                $table->foreignId('planned_occurrence_id')
                    ->constrained('planned_occurrences')
                    ->cascadeOnDelete();

                $table->foreignId('category_id')
                    ->constrained('categories')
                    ->restrictOnDelete();

                $table->foreignId('source_template_bill_id')
                    ->nullable()
                    ->constrained('planned_template_bills')
                    ->nullOnDelete();

                $table->decimal('expected_amount', 12, 2);
                $table->timestamps();
            });
        }

        if (Schema::hasIndex('planned_template_bills', 'pt_bills_template_category_unique')) {
            Schema::table('planned_template_bills', function (Blueprint $table) {
                $table->dropUnique('pt_bills_template_category_unique');
            });
        }

        if (Schema::hasIndex('planned_occurrence_bills', 'po_bills_occurrence_category_unique')) {
            Schema::table('planned_occurrence_bills', function (Blueprint $table) {
                $table->dropUnique('po_bills_occurrence_category_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_occurrence_bills');
        Schema::dropIfExists('planned_template_bills');

        Schema::table('planned_occurrences', function (Blueprint $table) {
            $table->dropColumn('bills_customized');
        });
    }
};
