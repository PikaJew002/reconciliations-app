<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('budget_years')) {
            Schema::create('budget_years', function (Blueprint $table) {
                $table->id();

                $table->foreignId('user_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table->date('starts_on');
                $table->string('label');
                $table->string('color', 7);
                $table->boolean('is_current')->default(false);

                $table->timestamps();

                $table->index(['user_id', 'starts_on']);
                $table->index(['user_id', 'is_current']);
            });
        }

        if (! Schema::hasColumn('budget_category_limits', 'budget_year_id')) {
            Schema::table('budget_category_limits', function (Blueprint $table) {
                $table->foreignId('budget_year_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('budget_years')
                    ->cascadeOnDelete();
            });
        }

        $this->migrateExistingLimits();

        // MySQL may use the (user_id, category_id) unique index to support the
        // user_id foreign key. Drop FKs before replacing that unique index.
        $this->dropForeignKeys([
            'user_id',
            'category_id',
            'budget_year_id',
        ]);

        $this->dropIndexIfExists(
            'budget_category_limits',
            'budget_category_limits_user_id_category_id_unique',
        );

        Schema::table('budget_category_limits', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnDelete();

            $table->foreign('budget_year_id')
                ->references('id')
                ->on('budget_years')
                ->cascadeOnDelete();
        });

        DB::table('budget_category_limits')
            ->whereNull('budget_year_id')
            ->delete();

        if ($this->budgetYearIdIsNullable()) {
            Schema::table('budget_category_limits', function (Blueprint $table) {
                $table->unsignedBigInteger('budget_year_id')->nullable(false)->change();
            });
        }

        if (! $this->indexExists(
            'budget_category_limits',
            'budget_category_limits_budget_year_id_category_id_unique',
        )) {
            Schema::table('budget_category_limits', function (Blueprint $table) {
                $table->unique(['budget_year_id', 'category_id']);
            });
        }
    }

    public function down(): void
    {
        $this->dropForeignKeys([
            'user_id',
            'category_id',
            'budget_year_id',
        ]);

        $this->dropIndexIfExists(
            'budget_category_limits',
            'budget_category_limits_budget_year_id_category_id_unique',
        );

        Schema::table('budget_category_limits', function (Blueprint $table) {
            $table->dropColumn('budget_year_id');
            $table->unique(['user_id', 'category_id']);
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnDelete();
        });

        Schema::dropIfExists('budget_years');
    }

    protected function migrateExistingLimits(): void
    {
        $userIds = DB::table('budget_category_limits')
            ->whereNull('budget_year_id')
            ->distinct()
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        $startsOn = Carbon::now()->startOfYear()->toDateString();
        $endsOn = Carbon::now()->startOfYear()->addYear()->subMonth()->endOfMonth();
        $label = Carbon::now()->startOfYear()->format('M Y').' – '.$endsOn->format('M Y');

        foreach ($userIds as $userId) {
            $existingYearId = DB::table('budget_years')
                ->where('user_id', $userId)
                ->where('is_current', true)
                ->value('id');

            $yearId = $existingYearId ?? DB::table('budget_years')->insertGetId([
                'user_id' => $userId,
                'starts_on' => $startsOn,
                'label' => $label,
                'color' => '#336699',
                'is_current' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('budget_category_limits')
                ->where('user_id', $userId)
                ->whereNull('budget_year_id')
                ->update(['budget_year_id' => $yearId]);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    protected function dropForeignKeys(array $columns): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('budget_category_limits'))
            ->keyBy(fn (array $foreignKey) => implode(',', $foreignKey['columns']));

        Schema::table('budget_category_limits', function (Blueprint $table) use ($columns, $foreignKeys) {
            foreach ($columns as $column) {
                if ($foreignKeys->has($column)) {
                    $table->dropForeign([$column]);
                }
            }
        });
    }

    protected function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropUnique($indexName);
        });
    }

    protected function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index) => ($index['name'] ?? null) === $indexName);
    }

    protected function budgetYearIdIsNullable(): bool
    {
        $column = collect(Schema::getColumns('budget_category_limits'))
            ->first(fn (array $column) => ($column['name'] ?? null) === 'budget_year_id');

        return $column !== null && ($column['nullable'] ?? false) === true;
    }
};
