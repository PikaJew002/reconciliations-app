<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'kind' => fake()->randomElement([Category::KIND_BILL, Category::KIND_EXPENSE]),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'color' => fake()->optional()->hexColor(),
            'icon' => null,
            'sort_order' => 0,
            'is_active' => true,
            'is_system' => false,
            'metadata' => null,
        ];
    }

    public function bill(): static
    {
        return $this->state(fn () => ['kind' => Category::KIND_BILL]);
    }

    public function expense(): static
    {
        return $this->state(fn () => ['kind' => Category::KIND_EXPENSE]);
    }
}
