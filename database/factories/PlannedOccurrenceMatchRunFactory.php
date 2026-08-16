<?php

namespace Database\Factories;

use App\Models\PlannedOccurrenceMatchRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedOccurrenceMatchRun>
 */
class PlannedOccurrenceMatchRunFactory extends Factory
{
    protected $model = PlannedOccurrenceMatchRun::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'error_message' => null,
            'metadata' => [],
        ];
    }
}
