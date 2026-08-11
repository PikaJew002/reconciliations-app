<?php

namespace Tests\Feature\Categories;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_categories(): void
    {
        $this->get(route('categories.index'))->assertRedirect('/login');
        $this->get(route('categories.create'))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_index_and_create_form(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $this->actingAs($user)
            ->get(route('categories.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Categories/Index')
                ->has('categories', 1)
                ->where('categories.0.name', 'Dining'));

        $this->actingAs($user)
            ->get(route('categories.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Categories/Create')
                ->has('kinds', 3));
    }

    public function test_user_can_create_income_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('categories.store'), [
            'name' => 'Salary',
            'kind' => Category::KIND_INCOME,
            'color' => '#112233',
        ])->assertRedirect(route('categories.index', ['kind' => Category::KIND_INCOME]));

        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Salary',
            'kind' => Category::KIND_INCOME,
            'slug' => 'salary',
        ]);
    }

    public function test_user_can_create_bill_and_expense_categories_with_same_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('categories.store'), [
            'name' => 'Utilities',
            'kind' => Category::KIND_BILL,
            'color' => '#112233',
        ])->assertRedirect(route('categories.index', ['kind' => Category::KIND_BILL]));

        $this->actingAs($user)->post(route('categories.store'), [
            'name' => 'Utilities',
            'kind' => Category::KIND_EXPENSE,
        ])->assertRedirect(route('categories.index', ['kind' => Category::KIND_EXPENSE]));

        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Utilities',
            'kind' => Category::KIND_BILL,
            'slug' => 'utilities',
            'color' => '#112233',
        ]);
        $this->assertDatabaseHas('categories', [
            'user_id' => $user->id,
            'name' => 'Utilities',
            'kind' => Category::KIND_EXPENSE,
            'slug' => 'utilities',
        ]);
    }

    public function test_store_validates_kind_and_color(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('categories.create'))
            ->post(route('categories.store'), [
                'name' => 'Bad',
                'kind' => 'not-a-kind',
                'color' => 'blue',
            ])
            ->assertRedirect(route('categories.create'))
            ->assertSessionHasErrors(['kind', 'color']);
    }

    public function test_user_can_update_own_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->expense()->create([
            'name' => 'Food',
            'slug' => 'food',
        ]);

        $this->actingAs($user)
            ->patch(route('categories.update', $category), [
                'name' => 'Groceries',
                'kind' => Category::KIND_EXPENSE,
                'color' => '#abcdef',
            ])
            ->assertRedirect(route('categories.index', ['kind' => Category::KIND_EXPENSE]));

        $category->refresh();
        $this->assertSame('Groceries', $category->name);
        $this->assertSame('groceries', $category->slug);
        $this->assertSame('#abcdef', $category->color);
    }

    public function test_user_cannot_edit_another_users_category(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $category = Category::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('categories.edit', $category))
            ->assertNotFound();

        $this->actingAs($other)
            ->patch(route('categories.update', $category), [
                'name' => 'Hijacked',
                'kind' => Category::KIND_EXPENSE,
            ])
            ->assertForbidden();
    }

    public function test_user_can_delete_unused_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->create(['name' => 'Temp']);

        $this->actingAs($user)
            ->delete(route('categories.destroy', $category))
            ->assertRedirect(route('categories.index', ['kind' => $category->kind]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_user_cannot_delete_category_in_use(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->for($user)->bill()->create(['name' => 'Internet']);

        BankTransaction::factory()->for($user)->create([
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'category_id' => $category->id,
            'status' => 'ignored',
        ]);

        $this->actingAs($user)
            ->delete(route('categories.destroy', $category))
            ->assertRedirect(route('categories.index', ['kind' => Category::KIND_BILL]))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_index_filters_by_kind(): void
    {
        $user = User::factory()->create();
        Category::factory()->for($user)->bill()->create(['name' => 'Rent']);
        Category::factory()->for($user)->expense()->create(['name' => 'Coffee']);

        $this->actingAs($user)
            ->get(route('categories.index', ['kind' => Category::KIND_BILL]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Categories/Index')
                ->has('categories', 1)
                ->where('categories.0.name', 'Rent')
                ->where('filters.kind', Category::KIND_BILL));
    }
}
