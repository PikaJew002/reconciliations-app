<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            // Re-open ignored, uncategorized income so it can go through the new categorize UI.
            DB::table('bank_transactions')
                ->where('status', 'ignored')
                ->where('classification', 'income')
                ->whereNull('category_id')
                ->update([
                    'status' => 'unmatched',
                    'classification' => null,
                    'classification_source' => null,
                    'classification_confidence' => null,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('transaction_categorization_rules')) {
            // Legacy boolean-income rules were migrated with null category_id.
            // Leaving them active would immediately re-ignore credits on the next categorize run.
            DB::table('transaction_categorization_rules')
                ->where('classification', 'income')
                ->whereNull('category_id')
                ->delete();
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup: prior ignored-income rows and null-category
        // income rules cannot be reconstructed reliably.
    }
};
