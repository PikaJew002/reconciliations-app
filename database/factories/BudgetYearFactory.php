<?php

namespace Database\Factories;

use App\Models\BudgetYear;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetYear>
 */
class BudgetYearFactory extends Factory
{
    protected $model = BudgetYear::class;

    public function definition(): array
    {
        $startsOn = Carbon::parse(fake()->date('Y-m-01'))->startOfMonth();

        return [
            'user_id' => User::factory(),
            'starts_on' => $startsOn->toDateString(),
            'label' => BudgetYear::labelForStart($startsOn),
            'color' => fake()->hexColor(),
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(fn () => ['is_current' => true]);
    }

    public function starting(string $yearMonth): static
    {
        $startsOn = Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfMonth();

        return $this->state(fn () => [
            'starts_on' => $startsOn->toDateString(),
            'label' => BudgetYear::labelForStart($startsOn),
        ]);
    }
}
