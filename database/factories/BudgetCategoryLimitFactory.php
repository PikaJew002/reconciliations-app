<?php

namespace Database\Factories;

use App\Models\BudgetCategoryLimit;
use App\Models\BudgetYear;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetCategoryLimit>
 */
class BudgetCategoryLimitFactory extends Factory
{
    protected $model = BudgetCategoryLimit::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'budget_year_id' => BudgetYear::factory(),
            'category_id' => Category::factory()->expense(),
            'amount' => fake()->randomFloat(2, 10, 500),
        ];
    }
}
