<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table) {
            $table->decimal('carry_forward', 12, 2)
                ->nullable()
                ->after('amount_customized');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table) {
            $table->dropColumn('carry_forward');
        });
    }
};
