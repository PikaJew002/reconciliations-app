<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_template_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('paycheck_template_id')
                ->constrained('planned_templates')
                ->cascadeOnDelete();

            $table->foreignId('bill_template_id')
                ->constrained('planned_templates')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique('bill_template_id', 'plan_assign_bill_unique');
            $table->unique(
                ['paycheck_template_id', 'bill_template_id'],
                'plan_assign_paycheck_bill_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_template_assignments');
    }
};
