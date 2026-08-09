<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('categories') && ! Schema::hasTable('expense_categories')) {
            // Already on the target schema (e.g. local DB rebuilt from interim migrations).
            $this->ensureBankTransactionCategoryColumn();
            $this->ensureCategorizationRulesTable();

            return;
        }

        $this->dropForeignKeyIfExists('products', 'expense_category_id');
        $this->dropForeignKeyIfExists('order_components', 'expense_category_id');

        // MySQL uses the (user_id, slug) unique as the supporting index for the
        // user_id foreign key, so both FKs must be dropped before the unique.
        $this->dropForeignKeyIfExists('expense_categories', 'parent_id');
        $this->dropForeignKeyIfExists('expense_categories', 'user_id');
        $this->dropIndexIfExists('expense_categories', 'expense_categories_user_id_slug_unique');

        Schema::rename('expense_categories', 'categories');

        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'kind')) {
                $table->string('kind')->default('expense')->after('parent_id');
            }
        });

        $this->addForeignKeyIfMissing('categories', 'user_id', 'users', 'id', 'cascade');
        $this->addForeignKeyIfMissing('categories', 'parent_id', 'categories', 'id', 'set null');

        if (! $this->indexExists('categories', 'categories_user_id_kind_slug_unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unique(['user_id', 'kind', 'slug']);
            });
        }

        if (! $this->indexExists('categories', 'categories_kind_index')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->index('kind');
            });
        }

        if (Schema::hasColumn('products', 'expense_category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('expense_category_id', 'category_id');
            });
        }

        $this->addForeignKeyIfMissing('products', 'category_id', 'categories', 'id', 'set null');

        if (Schema::hasColumn('order_components', 'expense_category_id')) {
            Schema::table('order_components', function (Blueprint $table) {
                $table->renameColumn('expense_category_id', 'category_id');
            });
        }

        $this->addForeignKeyIfMissing('order_components', 'category_id', 'categories', 'id', 'set null');

        $this->ensureBankTransactionCategoryColumn();
        $this->ensureCategorizationRulesTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_categorization_rules');

        if (Schema::hasColumn('bank_transactions', 'category_id')) {
            $this->dropForeignKeyIfExists('bank_transactions', 'category_id');
            Schema::table('bank_transactions', function (Blueprint $table) {
                $table->dropColumn('category_id');
            });
        }

        if (! Schema::hasTable('categories')) {
            return;
        }

        $this->dropForeignKeyIfExists('products', 'category_id');
        $this->dropForeignKeyIfExists('order_components', 'category_id');

        if (Schema::hasColumn('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('category_id', 'expense_category_id');
            });
        }

        if (Schema::hasColumn('order_components', 'category_id')) {
            Schema::table('order_components', function (Blueprint $table) {
                $table->renameColumn('category_id', 'expense_category_id');
            });
        }

        $this->dropForeignKeyIfExists('categories', 'parent_id');
        $this->dropForeignKeyIfExists('categories', 'user_id');
        $this->dropIndexIfExists('categories', 'categories_user_id_kind_slug_unique');
        $this->dropIndexIfExists('categories', 'categories_kind_index');

        if (Schema::hasColumn('categories', 'kind')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }

        Schema::rename('categories', 'expense_categories');

        $this->addForeignKeyIfMissing('expense_categories', 'user_id', 'users', 'id', 'cascade');
        $this->addForeignKeyIfMissing('expense_categories', 'parent_id', 'expense_categories', 'id', 'set null');

        if (! $this->indexExists('expense_categories', 'expense_categories_user_id_slug_unique')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->unique(['user_id', 'slug']);
            });
        }

        $this->addForeignKeyIfMissing('products', 'expense_category_id', 'expense_categories', 'id', 'set null');
        $this->addForeignKeyIfMissing('order_components', 'expense_category_id', 'expense_categories', 'id', 'set null');
    }

    private function ensureBankTransactionCategoryColumn(): void
    {
        if (Schema::hasColumn('bank_transactions', 'category_id')) {
            return;
        }

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('classification_confidence')
                ->constrained('categories')
                ->nullOnDelete();
        });
    }

    private function ensureCategorizationRulesTable(): void
    {
        if (! Schema::hasTable('transaction_categorization_rules')) {
            Schema::create('transaction_categorization_rules', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->foreignId('category_id')
                    ->constrained('categories')
                    ->cascadeOnDelete();

                $table->string('classification');

                $table->string('match_mode');

                $table->foreignId('merchant_id')
                    ->nullable()
                    ->constrained('merchants')
                    ->nullOnDelete();

                $table->string('normalized_pattern')
                    ->nullable();

                $table->decimal('amount', 12, 2)
                    ->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();
            });
        }

        if (! $this->indexExists('transaction_categorization_rules', 'txn_cat_rules_user_active_index')) {
            Schema::table('transaction_categorization_rules', function (Blueprint $table) {
                $table->index(['user_id', 'is_active'], 'txn_cat_rules_user_active_index');
            });
        }

        if (! $this->indexExists('transaction_categorization_rules', 'txn_cat_rules_user_class_mode_index')) {
            Schema::table('transaction_categorization_rules', function (Blueprint $table) {
                $table->index(
                    ['user_id', 'classification', 'match_mode'],
                    'txn_cat_rules_user_class_mode_index',
                );
            });
        }
    }

    private function dropForeignKeyIfExists(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });

            return;
        }

        $foreignKey = $this->foreignKeyName($table, $column);

        if ($foreignKey === null) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($foreignKey) {
            $blueprint->dropForeign($foreignKey);
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
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $expected = $table.'_'.$column.'_foreign';

            // SQLite: attempt drop via conventional name when the column exists.
            return Schema::hasColumn($table, $column) ? $expected : null;
        }

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
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $indexes = Schema::getConnection()->select("pragma index_list('{$table}')");

            foreach ($indexes as $entry) {
                if (($entry->name ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }

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
