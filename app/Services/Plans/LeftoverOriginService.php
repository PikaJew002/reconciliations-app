<?php

namespace App\Services\Plans;

use App\Models\BankTransaction;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class LeftoverOriginService
{
    public function __construct(
        protected PlannedOccurrenceGenerator $generator,
    ) {}

    /**
     * Date leftover windows start from. Null until the user has a paycheck plan.
     * The first time a plan exists, this locks to the current calendar month
     * so last-month occurrences (and older history) are not chained in.
     */
    public function ensureForUser(int $userId): ?CarbonInterface
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        if ($user->leftover_starts_on !== null) {
            return $user->leftover_starts_on->copy()->startOfDay();
        }

        if (! $this->hasPaycheckTemplates($userId)) {
            return null;
        }

        $startsOn = Carbon::now()->startOfMonth()->startOfDay();
        $user->forceFill(['leftover_starts_on' => $startsOn->toDateString()])->save();

        return $startsOn;
    }

    public function setMonth(User $user, string $month): CarbonInterface
    {
        $startsOn = Carbon::createFromFormat('Y-m', $month);

        if ($startsOn === false) {
            throw new \InvalidArgumentException("Invalid leftover start month [{$month}].");
        }

        $startsOn = $startsOn->startOfMonth()->startOfDay();
        $user->forceFill(['leftover_starts_on' => $startsOn->toDateString()])->save();

        return $startsOn;
    }

    public function setCarryOver(User $user, float $amount): float
    {
        $amount = round($amount, 2);
        $user->forceFill(['leftover_carry_over' => $amount])->save();

        return $amount;
    }

    public function carryOverForUser(int $userId): float
    {
        $user = User::query()->find($userId);

        if ($user === null) {
            return 0.0;
        }

        return round((float) ($user->leftover_carry_over ?? 0), 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(int $userId): ?array
    {
        $this->generator->ensureForUser($userId);

        $startsOn = $this->ensureForUser($userId);

        if ($startsOn === null) {
            return null;
        }

        $occurrences = $this->incomeOccurrences($userId);
        $originOccurrence = $occurrences->first(
            fn (PlannedOccurrence $occurrence) => ! $occurrence->periodDate()->lt($startsOn),
        );

        return [
            'month' => $startsOn->format('Y-m'),
            'starts_on' => $startsOn->toDateString(),
            'carry_over' => $this->carryOverForUser($userId),
            'paycheck' => $originOccurrence !== null
                ? [
                    'id' => (int) $originOccurrence->template_id,
                    'occurrence_id' => (int) $originOccurrence->id,
                    'name' => $originOccurrence->template?->name,
                    'date' => $originOccurrence->expected_date->toDateString(),
                ]
                : null,
            'months' => $this->monthOptions($occurrences, $startsOn, $originOccurrence),
        ];
    }

    protected function hasPaycheckTemplates(int $userId): bool
    {
        return PlannedTemplate::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->exists();
    }

    /**
     * @return Collection<int, PlannedOccurrence>
     */
    protected function incomeOccurrences(int $userId): Collection
    {
        return PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->where('classification', BankTransaction::CLASSIFICATION_INCOME)
            ->whereNotNull('template_id')
            ->with('template:id,name')
            ->orderBy('scheduled_date')
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, PlannedOccurrence>  $occurrences
     * @return list<array{value: string, label: string, paycheck_name: ?string, paycheck_date: ?string}>
     */
    protected function monthOptions(
        Collection $occurrences,
        CarbonInterface $startsOn,
        ?PlannedOccurrence $originOccurrence,
    ): array {
        $options = $occurrences
            ->groupBy(fn (PlannedOccurrence $occurrence) => $occurrence->periodDate()->format('Y-m'))
            ->map(function (Collection $monthOccurrences, string $month) {
                $first = $monthOccurrences->first();

                return [
                    'value' => $month,
                    'label' => Carbon::createFromFormat('Y-m-d', $month.'-01')->format('F Y'),
                    'paycheck_name' => $first?->template?->name,
                    'paycheck_date' => $first?->expected_date->toDateString(),
                ];
            });

        $monthKey = $startsOn->format('Y-m');

        if (! $options->has($monthKey)) {
            $options->put($monthKey, [
                'value' => $monthKey,
                'label' => $startsOn->format('F Y'),
                'paycheck_name' => $originOccurrence?->template?->name,
                'paycheck_date' => $originOccurrence?->expected_date->toDateString(),
            ]);
        }

        return $options
            ->sortKeys()
            ->values()
            ->all();
    }
}
