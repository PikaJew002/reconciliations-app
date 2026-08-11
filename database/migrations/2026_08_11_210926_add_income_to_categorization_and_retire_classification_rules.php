<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->makeCategorizationRuleCategoryNullable();
        $this->migrateConfirmedIncomeRules();
        $this->clearHeuristicSuggestedIncome();
        Schema::dropIfExists('transaction_classification_rules');
    }

    public function down(): void
    {
        if (! Schema::hasTable('transaction_classification_rules')) {
            Schema::create('transaction_classification_rules', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('normalized_pattern');

                $table->string('classification');

                $table->string('direction')->default('credit');

                $table->string('origin');

                $table->string('match_mode')->default('description');

                $table->decimal('amount', 12, 2)->nullable();

                $table->boolean('is_active')->default(true);

                $table->json('metadata')->nullable();

                $table->timestamps();

                $table->index(['user_id', 'is_active']);
            });
        }

        if (Schema::hasTable('transaction_categorization_rules')) {
            $incomeRules = DB::table('transaction_categorization_rules')
                ->where('classification', 'income')
                ->whereNotNull('normalized_pattern')
                ->get();

            foreach ($incomeRules as $rule) {
                DB::table('transaction_classification_rules')->insert([
                    'user_id' => $rule->user_id,
                    'normalized_pattern' => $rule->normalized_pattern,
                    'classification' => 'income',
                    'direction' => 'credit',
                    'origin' => 'user_confirmed',
                    'match_mode' => $rule->match_mode,
                    'amount' => $rule->amount,
                    'is_active' => $rule->is_active,
                    'metadata' => null,
                    'created_at' => $rule->created_at,
                    'updated_at' => $rule->updated_at,
                ]);
            }

            DB::table('transaction_categorization_rules')
                ->where('classification', 'income')
                ->delete();
        }

        $this->makeCategorizationRuleCategoryRequired();
    }

    private function makeCategorizationRuleCategoryNullable(): void
    {
        if (! Schema::hasTable('transaction_categorization_rules')) {
            return;
        }

        if (! Schema::hasColumn('transaction_categorization_rules', 'category_id')) {
            return;
        }

        $this->dropForeignKeyIfExists('transaction_categorization_rules', 'category_id');

        Schema::table('transaction_categorization_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->change();
        });

        Schema::table('transaction_categorization_rules', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();
        });
    }

    private function makeCategorizationRuleCategoryRequired(): void
    {
        if (! Schema::hasTable('transaction_categorization_rules')) {
            return;
        }

        if (! Schema::hasColumn('transaction_categorization_rules', 'category_id')) {
            return;
        }

        DB::table('transaction_categorization_rules')
            ->whereNull('category_id')
            ->delete();

        $this->dropForeignKeyIfExists('transaction_categorization_rules', 'category_id');

        Schema::table('transaction_categorization_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
        });

        Schema::table('transaction_categorization_rules', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnDelete();
        });
    }

    private function migrateConfirmedIncomeRules(): void
    {
        if (
            ! Schema::hasTable('transaction_classification_rules')
            || ! Schema::hasTable('transaction_categorization_rules')
        ) {
            return;
        }

        $rules = DB::table('transaction_classification_rules')
            ->where('classification', 'income')
            ->where('origin', 'user_confirmed')
            ->where('is_active', true)
            ->whereIn('match_mode', ['description', 'exact_description_and_amount'])
            ->whereNotNull('normalized_pattern')
            ->where('normalized_pattern', '!=', '')
            ->get();

        $now = now();

        foreach ($rules as $rule) {
            $exists = DB::table('transaction_categorization_rules')
                ->where('user_id', $rule->user_id)
                ->where('classification', 'income')
                ->where('match_mode', $rule->match_mode)
                ->whereNull('merchant_id')
                ->where('normalized_pattern', $rule->normalized_pattern)
                ->when(
                    $rule->amount === null,
                    fn ($query) => $query->whereNull('amount'),
                    fn ($query) => $query->where('amount', $rule->amount),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('transaction_categorization_rules')->insert([
                'user_id' => $rule->user_id,
                'category_id' => null,
                'classification' => 'income',
                'match_mode' => $rule->match_mode,
                'merchant_id' => null,
                'normalized_pattern' => $rule->normalized_pattern,
                'amount' => $rule->amount,
                'is_active' => true,
                'created_at' => $rule->created_at ?? $now,
                'updated_at' => $rule->updated_at ?? $now,
            ]);
        }
    }

    private function clearHeuristicSuggestedIncome(): void
    {
        if (! Schema::hasTable('bank_transactions')) {
            return;
        }

        DB::table('bank_transactions')
            ->where('status', 'unmatched')
            ->where('classification', 'income')
            ->update([
                'classification' => null,
                'classification_source' => null,
                'classification_confidence' => null,
                'updated_at' => now(),
            ]);
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

    private function foreignKeyName(string $table, string $column): ?string
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return Schema::hasColumn($table, $column)
                ? $table.'_'.$column.'_foreign'
                : null;
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
};
