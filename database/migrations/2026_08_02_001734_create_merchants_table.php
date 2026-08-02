<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            // Canonical lowercase version for matching
            $table->string('normalized_name');

            $table->string('website')->nullable();

            $table->enum('type', [
                'retailer',
                'restaurant',
                'service',
                'utility',
                'financial',
                'government',
                'other',
            ])->default('retailer');

            // Whether this merchant supports importing order history
            $table->boolean('supports_order_import')
                ->default(false);

            // Future scraper/API support
            $table->boolean('supports_api')
                ->default(false);

            // Matching aliases, import hints, etc.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique([
                'user_id',
                'normalized_name',
            ]);

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
