<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        $name = fake()->randomElement([
            'Groceries',
            'Household',
            'Clothing',
            'Dining Out',
            'Transportation',
            'Entertainment',
            'Utilities',
        ]);

        return [
            'user_id' => User::factory(),

            'parent_id' => null,

            'name' => $name,

            'slug' => Str::slug($name),

            'color' => fake()->optional()->hexColor(),

            'icon' => fake()->optional()->randomElement([
                'shopping-cart',
                'home',
                'shirt',
                'utensils',
                'car',
                'film',
                'bolt',
            ]),

            'sort_order' => fake()->numberBetween(0, 100),

            'is_active' => true,

            'is_system' => false,

            'metadata' => [],
        ];
    }
}
