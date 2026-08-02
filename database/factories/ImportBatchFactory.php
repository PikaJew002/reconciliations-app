<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),

            'source' => fake()->randomElement([
                'bank',
                'walmart',
            ]),

            'type' => fake()->randomElement([
                'transactions',
                'orders',
                'combined',
            ]),

            'original_filename' => fake()->word().'.csv',

            'storage_path' => 'imports/'.fake()->uuid().'.csv',

            'record_count' => fake()->numberBetween(1, 500),

            'status' => 'completed',

            'started_at' => now(),

            'completed_at' => now(),

            'metadata' => [],
        ];
    }
}
