<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('imported_total', 10, 2)->nullable()->after('total');
        });

        DB::table('orders')->update([
            'imported_total' => DB::raw('total'),
        ]);

        Schema::table('order_components', function (Blueprint $table) {
            $table->decimal('refund_amount', 10, 2)->nullable()->after('amount');
            $table->string('refund_kind')->nullable()->after('refund_amount');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transaction_allocations MODIFY allocation_type ENUM('automatic', 'manual', 'imported', 'refund') NOT NULL DEFAULT 'automatic'");
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteAllocationTypes(includeRefund: true);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE transaction_allocations MODIFY allocation_type ENUM('automatic', 'manual', 'imported') NOT NULL DEFAULT 'automatic'");
        }

        if ($driver === 'sqlite') {
            $this->rebuildSqliteAllocationTypes(includeRefund: false);
        }

        Schema::table('order_components', function (Blueprint $table) {
            $table->dropColumn(['refund_amount', 'refund_kind']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('imported_total');
        });
    }

    protected function rebuildSqliteAllocationTypes(bool $includeRefund): void
    {
        $allowed = $includeRefund
            ? ['automatic', 'manual', 'imported', 'refund']
            : ['automatic', 'manual', 'imported'];

        Schema::disableForeignKeyConstraints();

        Schema::create('transaction_allocations_rebuilt', function (Blueprint $table) use ($allowed) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('allocated_amount', 10, 2);
            $table->enum('allocation_type', $allowed)->default('automatic');
            $table->decimal('match_confidence', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('bank_transaction_id');
            $table->index('order_component_id');
            $table->index('allocation_type');
            $table->unique(
                ['bank_transaction_id', 'order_component_id'],
                'transaction_allocations_rebuilt_unique',
            );
        });

        DB::statement('INSERT INTO transaction_allocations_rebuilt (
            id, bank_transaction_id, order_component_id, allocated_amount, allocation_type,
            match_confidence, notes, metadata, created_at, updated_at
        ) SELECT
            id, bank_transaction_id, order_component_id, allocated_amount, allocation_type,
            match_confidence, notes, metadata, created_at, updated_at
        FROM transaction_allocations');

        Schema::drop('transaction_allocations');
        Schema::rename('transaction_allocations_rebuilt', 'transaction_allocations');

        Schema::enableForeignKeyConstraints();
    }
};
