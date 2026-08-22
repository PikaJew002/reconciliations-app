<?php

use App\Services\Merchants\MerchantMatchingRuleBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchant_matching_rules')) {
            Schema::create('merchant_matching_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
                $table->string('match_mode');
                $table->string('pattern');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'match_mode', 'pattern']);
                $table->index(['merchant_id', 'is_active']);
            });
        }

        if (app()->runningUnitTests()) {
            return;
        }

        app(MerchantMatchingRuleBackfill::class)->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_matching_rules');
    }
};
