<?php

namespace Database\Factories;

use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReconciliationRunFactory extends Factory
{
    protected $model = ReconciliationRun::class;

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
