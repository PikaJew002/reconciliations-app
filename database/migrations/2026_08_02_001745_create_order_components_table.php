<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_components', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')
                ->constrained()
                ->cascadeOnDelete();

            // Null for delivery, tip, etc.
            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('type');

            // Product Name, Sales Tax, Delivery Fee, etc.
            $table->string('description');

            $table->decimal('amount', 10, 2);

            // Future reporting
            $table->foreignId('expense_category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Used by AI/manual categorization
            $table->decimal('category_confidence', 5, 2)
                ->nullable();

            $table->boolean('is_user_modified')
                ->default(false);

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('order_item_id');
            $table->index('expense_category_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_components');
    }
};
