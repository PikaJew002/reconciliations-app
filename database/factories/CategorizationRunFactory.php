<?php

namespace Database\Factories;

use App\Models\CategorizationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategorizationRun>
 */
class CategorizationRunFactory extends Factory
{
    protected $model = CategorizationRun::class;

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
