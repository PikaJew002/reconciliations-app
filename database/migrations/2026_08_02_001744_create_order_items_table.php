<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            // Optional until product matching succeeds
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Position within the imported order
            $table->unsignedInteger('line_number');

            // Merchant identifiers
            $table->string('sku')->nullable();
            $table->string('upc')->nullable();

            // Original description from merchant
            $table->string('description');

            // Normalized version used for matching
            $table->string('normalized_description')->nullable();

            $table->decimal('quantity', 8, 3)
                ->default(1);

            $table->decimal('unit_price', 10, 2);

            $table->decimal('extended_price', 10, 2);

            // Whether this line is taxable
            $table->boolean('taxable')
                ->default(true);

            // Confidence that the product match is correct
            $table->decimal('match_confidence', 5, 2)
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
            $table->index('sku');
            $table->index('upc');

            $table->unique([
                'order_id',
                'line_number',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
