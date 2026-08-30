<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table) {
            if (! Schema::hasColumn('planned_occurrences', 'scheduled_date')) {
                $table->date('scheduled_date')->nullable()->after('amount');
            }

            if (! Schema::hasColumn('planned_occurrences', 'date_customized')) {
                $table->boolean('date_customized')->default(false)->after('expected_amount');
            }

            if (! Schema::hasColumn('planned_occurrences', 'amount_customized')) {
                $table->boolean('amount_customized')->default(false)->after('date_customized');
            }
        });

        DB::table('planned_occurrences')
            ->whereNull('scheduled_date')
            ->update([
                'scheduled_date' => DB::raw('expected_date'),
            ]);

        Schema::table('planned_occurrences', function (Blueprint $table) {
            if (! Schema::hasIndex('planned_occurrences', 'planned_occurrences_template_id_scheduled_date_unique')) {
                $table->unique(['template_id', 'scheduled_date']);
            }
        });

        Schema::table('planned_occurrences', function (Blueprint $table) {
            if (Schema::hasIndex('planned_occurrences', 'planned_occurrences_template_id_expected_date_unique')) {
                $table->dropUnique(['template_id', 'expected_date']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table) {
            if (! Schema::hasIndex('planned_occurrences', 'planned_occurrences_template_id_expected_date_unique')) {
                $table->unique(['template_id', 'expected_date']);
            }
        });

        Schema::table('planned_occurrences', function (Blueprint $table) {
            if (Schema::hasIndex('planned_occurrences', 'planned_occurrences_template_id_scheduled_date_unique')) {
                $table->dropUnique(['template_id', 'scheduled_date']);
            }

            $table->dropColumn([
                'scheduled_date',
                'date_customized',
                'amount_customized',
            ]);
        });
    }
};
