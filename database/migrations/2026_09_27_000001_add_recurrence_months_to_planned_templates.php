<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('planned_templates', function (Blueprint $table) {
            $table->unsignedTinyInteger('recurrence_months')->default(1)->after('occurrences_starts_on');
        });
    }

    public function down(): void
    {
        Schema::table('planned_templates', function (Blueprint $table) {
            $table->dropColumn('recurrence_months');
        });
    }
};
