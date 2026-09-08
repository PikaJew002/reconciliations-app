<?php

namespace App\Services\Plans;

use App\Models\VacationWindow;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VacationWindowService
{
    /**
     * @var array<int, Collection<int, VacationWindow>>
     */
    protected array $windowsByUser = [];

    /**
     * @return Collection<int, VacationWindow>
     */
    public function windowsForUser(int $userId): Collection
    {
        return $this->windowsByUser[$userId] ??= VacationWindow::query()
            ->where('user_id', $userId)
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    public function covers(int $userId, CarbonInterface|string|null $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        $day = Carbon::parse($date)->startOfDay();

        return $this->windowsForUser($userId)->contains(
            function (VacationWindow $window) use ($day): bool {
                return $day->gte($window->starts_on->copy()->startOfDay())
                    && $day->lte($window->ends_on->copy()->startOfDay());
            },
        );
    }

    /**
     * Limit an Order query to rows whose ordered_at is not in any vacation window.
     */
    public function whereOrderNotCovered(Builder $query, int $userId): Builder
    {
        $windows = $this->windowsForUser($userId);

        if ($windows->isEmpty()) {
            return $query;
        }

        foreach ($windows as $window) {
            $query->where(function (Builder $builder) use ($window): void {
                $builder
                    ->whereDate('ordered_at', '<', $window->starts_on->toDateString())
                    ->orWhereDate('ordered_at', '>', $window->ends_on->toDateString())
                    ->orWhereNull('ordered_at');
            });
        }

        return $query;
    }

    /**
     * Limit an Order query to rows whose ordered_at falls in any vacation window.
     */
    public function whereOrderCovered(Builder $query, int $userId): Builder
    {
        $windows = $this->windowsForUser($userId);

        if ($windows->isEmpty()) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $builder) use ($windows): void {
            foreach ($windows as $window) {
                $builder->orWhere(function (Builder $range) use ($window): void {
                    $range
                        ->whereDate('ordered_at', '>=', $window->starts_on->toDateString())
                        ->whereDate('ordered_at', '<=', $window->ends_on->toDateString());
                });
            }
        });
    }

    /**
     * @return list<array{id: int, name: ?string, starts_on: string, ends_on: string}>
     */
    public function payloadForUser(int $userId): array
    {
        return $this->windowsForUser($userId)
            ->map(fn (VacationWindow $window): array => [
                'id' => $window->id,
                'name' => $window->name,
                'starts_on' => $window->starts_on->toDateString(),
                'ends_on' => $window->ends_on->toDateString(),
            ])
            ->values()
            ->all();
    }
}
