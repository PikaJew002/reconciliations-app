<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\PlannedOccurrence;
use App\Models\PlannedOccurrenceBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedOccurrenceBill>
 */
class PlannedOccurrenceBillFactory extends Factory
{
    protected $model = PlannedOccurrenceBill::class;

    public function definition(): array
    {
        return [
            'planned_occurrence_id' => PlannedOccurrence::factory(),
            'category_id' => Category::factory()->bill(),
            'source_template_bill_id' => null,
            'expected_amount' => 1200.00,
        ];
    }
}
