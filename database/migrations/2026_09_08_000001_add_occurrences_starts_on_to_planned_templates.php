<?php

use App\Models\BankTransaction;
use App\Models\PlannedTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('planned_templates', function (Blueprint $table) {
            $table->date('occurrences_starts_on')->nullable()->after('expected_amount');
        });

        PlannedTemplate::query()
            ->where('classification', BankTransaction::CLASSIFICATION_BILL)
            ->orderBy('id')
            ->each(function (PlannedTemplate $template): void {
                $leftoverStartsOn = User::query()
                    ->whereKey($template->user_id)
                    ->value('leftover_starts_on');

                $startsOn = $leftoverStartsOn !== null
                    ? Carbon::parse($leftoverStartsOn)->startOfMonth()->startOfDay()
                    : Carbon::parse($template->created_at ?? now())
                        ->startOfMonth()
                        ->subMonth()
                        ->startOfDay();

                $template->forceFill([
                    'occurrences_starts_on' => $startsOn->toDateString(),
                ])->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('planned_templates', function (Blueprint $table) {
            $table->dropColumn('occurrences_starts_on');
        });
    }
};
