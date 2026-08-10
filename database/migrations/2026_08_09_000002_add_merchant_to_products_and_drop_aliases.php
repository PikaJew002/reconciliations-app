<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_aliases');

        if (Schema::hasColumn('products', 'merchant_id')) {
            return;
        }

        // MySQL uses (user_id, normalized_name) as the supporting index for the
        // user_id foreign key, so the FK must be dropped before the unique.
        if ($this->isMysql()) {
            $this->dropForeignKeyIfExists('products', 'user_id');
            $this->dropIndexIfExists('products', 'products_user_id_normalized_name_unique');
        } else {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'normalized_name']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('merchant_id')
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('sku')
                ->nullable()
                ->after('normalized_name');

            $table->unique(['user_id', 'merchant_id', 'normalized_name']);
            $table->unique(['user_id', 'merchant_id', 'sku']);
            $table->index('sku');
        });

        if ($this->isMysql()) {
            $this->addForeignKeyIfMissing('products', 'user_id', 'users', 'id', 'cascade');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'merchant_id')) {
            return;
        }

        if ($this->isMysql()) {
            $this->dropForeignKeyIfExists('products', 'user_id');
            $this->dropIndexIfExists('products', 'products_user_id_merchant_id_normalized_name_unique');
            $this->dropIndexIfExists('products', 'products_user_id_merchant_id_sku_unique');
            $this->dropIndexIfExists('products', 'products_sku_index');
        } else {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique(['user_id', 'merchant_id', 'normalized_name']);
                $table->dropUnique(['user_id', 'merchant_id', 'sku']);
                $table->dropIndex(['sku']);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merchant_id');
            $table->dropColumn('sku');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['user_id', 'normalized_name']);
        });

        if ($this->isMysql()) {
            $this->addForeignKeyIfMissing('products', 'user_id', 'users', 'id', 'cascade');
        }

        if (! Schema::hasTable('product_aliases')) {
            Schema::create('product_aliases', function (Blueprint $table) {
                $table->id();

                $table->foreignId('product_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('merchant_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('merchant_description');
                $table->string('normalized_description');
                $table->string('sku')->nullable();
                $table->string('upc')->nullable();
                $table->decimal('match_confidence', 5, 2)->nullable();
                $table->boolean('is_user_confirmed')->default(false);
                $table->json('metadata')->nullable();
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
    }

    private function isMysql(): bool
    {
        return Schema::getConnection()->getDriverName() === 'mysql';
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        $foreign = $this->foreignKeyName($table, $column);

        if ($foreign === null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($foreign) {
            $blueprint->dropForeign($foreign);
        });
    }

    private function addForeignKeyIfMissing(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete,
    ): void {
        if (! Schema::hasColumn($table, $column) || $this->foreignKeyName($table, $column) !== null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $referencedColumn, $onDelete) {
            $foreign = $blueprint->foreign($column)
                ->references($referencedColumn)
                ->on($referencedTable);

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->nullOnDelete();
            }
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropIndex($index);
        });
    }

    private function foreignKeyName(string $table, string $column): ?string
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'select CONSTRAINT_NAME as name
             from information_schema.KEY_COLUMN_USAGE
             where TABLE_SCHEMA = ?
               and TABLE_NAME = ?
               and COLUMN_NAME = ?
               and REFERENCED_TABLE_NAME is not null
             limit 1',
            [$database, $table, $column],
        );

        return $row->name ?? null;
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'select INDEX_NAME as name
             from information_schema.STATISTICS
             where TABLE_SCHEMA = ?
               and TABLE_NAME = ?
               and INDEX_NAME = ?
             limit 1',
            [$database, $table, $index],
        );

        return $row !== null;
    }
};
