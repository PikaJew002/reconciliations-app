<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('expense_category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Canonical product name
            $table->string('name');

            // Used for matching
            $table->string('normalized_name');

            $table->string('brand')
                ->nullable();

            $table->string('upc')
                ->nullable();

            $table->string('size')
                ->nullable();

            $table->string('unit')
                ->nullable();

            $table->boolean('is_taxable')
                ->default(true);

            $table->decimal('category_confidence', 5, 2)
                ->nullable();

            $table->boolean('is_user_modified')
                ->default(false);

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'user_id',
                'normalized_name',
            ]);

            $table->index('brand');
            $table->index('upc');
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
