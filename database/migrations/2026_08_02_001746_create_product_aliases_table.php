<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('merchant_id')
                ->constrained()
                ->cascadeOnDelete();

            // Original merchant description
            $table->string('merchant_description');

            // Normalized for matching
            $table->string('normalized_description');

            $table->string('sku')
                ->nullable();

            $table->string('upc')
                ->nullable();

            $table->decimal('match_confidence', 5, 2)
                ->nullable();

            $table->boolean('is_user_confirmed')
                ->default(false);

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'merchant_id',
                'normalized_description',
            ]);

            $table->index('product_id');
            $table->index('merchant_id');
            $table->index('sku');
            $table->index('upc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_aliases');
    }
};
