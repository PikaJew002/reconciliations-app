<?php

use App\Models\PlannedTemplate;
use App\Services\Plans\PlannedOccurrenceGenerator;
use App\Services\Plans\PlannedOccurrenceMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('planned_templates') || ! Schema::hasTable('planned_occurrences')) {
            return;
        }

        app(PlannedOccurrenceGenerator::class)->backfillAll();

        $userIds = PlannedTemplate::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('user_id');

        $matcher = app(PlannedOccurrenceMatcher::class);

        foreach ($userIds as $userId) {
            $matcher->matchForUser((int) $userId);
        }
    }

    public function down(): void
    {
        // Recreated occurrences cannot be distinguished from ones that
        // were always present.
    }
};
