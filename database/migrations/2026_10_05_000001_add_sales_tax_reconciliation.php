<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('sales_tax_rate', 8, 5)
                ->default(0.06000)
                ->after('leftover_carry_over');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_taxable')->nullable()->default(null)->change();
        });

        DB::table('products')->update(['is_taxable' => null]);

        Schema::create('component_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('normalized_description');
            $table->boolean('is_taxable');
            $table->timestamps();

            $table->unique(
                ['user_id', 'merchant_id', 'type', 'normalized_description'],
                'component_tax_rules_identity_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_tax_rules');

        DB::table('products')->whereNull('is_taxable')->update(['is_taxable' => true]);

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_taxable')->nullable(false)->default(true)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sales_tax_rate');
        });
    }
};
