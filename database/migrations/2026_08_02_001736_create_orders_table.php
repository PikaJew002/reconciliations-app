<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('import_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('merchant_id')
                ->constrained()
                ->cascadeOnDelete();

            // Merchant's order identifier
            $table->string('order_number');

            $table->dateTime('ordered_at')->nullable();

            $table->dateTime('fulfilled_at')->nullable();

            $table->dateTime('delivered_at')->nullable();

            $table->decimal('subtotal', 10, 2);

            $table->decimal('tax', 10, 2)
                ->default(0);

            $table->decimal('delivery_fee', 10, 2)
                ->default(0);

            $table->decimal('tip', 10, 2)
                ->default(0);

            $table->decimal('discount', 10, 2)
                ->default(0);

            $table->decimal('total', 10, 2);

            $table->string('currency', 3)
                ->default('USD');

            $table->string('shipping_state', 2)
                ->nullable();

            $table->string('shipping_zip', 10)
                ->nullable();

            $table->string('status')
                ->default('imported');

            $table->text('notes')
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'merchant_id',
                'order_number',
            ]);

            $table->index('ordered_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
