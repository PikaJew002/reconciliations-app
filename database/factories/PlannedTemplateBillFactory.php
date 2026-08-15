<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\PlannedTemplate;
use App\Models\PlannedTemplateBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlannedTemplateBill>
 */
class PlannedTemplateBillFactory extends Factory
{
    protected $model = PlannedTemplateBill::class;

    public function definition(): array
    {
        return [
            'planned_template_id' => PlannedTemplate::factory(),
            'category_id' => Category::factory()->bill(),
            'expected_amount' => 1200.00,
        ];
    }
}
