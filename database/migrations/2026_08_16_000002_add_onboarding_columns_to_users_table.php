<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_hidden_at')->nullable();
            $table->json('onboarding_skipped_steps')->nullable();
            $table->json('onboarding_tours')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_hidden_at',
                'onboarding_skipped_steps',
                'onboarding_tours',
            ]);
        });
    }
};
