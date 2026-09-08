<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VacationWindow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VacationWindow>
 */
class VacationWindowFactory extends Factory
{
    protected $model = VacationWindow::class;

    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('-1 month', '+1 month');

        return [
            'user_id' => User::factory(),
            'name' => fake()->optional()->words(2, true),
            'starts_on' => $startsOn->format('Y-m-d'),
            'ends_on' => (clone $startsOn)->modify('+7 days')->format('Y-m-d'),
        ];
    }
}
