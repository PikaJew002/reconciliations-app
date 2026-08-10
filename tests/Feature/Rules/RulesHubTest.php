<?php

namespace Tests\Feature\Rules;

use App\Models\Category;
use App\Models\TransactionCategorizationRule;
use App\Models\TransactionClassificationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RulesHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_rules_index_lists_income_and_expense_rules(): void
    {
        $user = User::factory()->create();

        TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'venmo cashout',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'amount' => 500.00,
            'is_active' => true,
            'metadata' => [],
        ]);

        $category = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => 'expense',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'chipotle',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('rules.index', ['tab' => 'income']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Rules/Index')
                ->where('tab', 'income')
                ->has('incomeRules', 1)
                ->where('incomeRules.0.normalized_pattern', 'venmo cashout')
                ->has('expenseRules', 1)
                ->where('expenseRules.0.category.name', 'Dining')
            );
    }

    public function test_user_can_toggle_and_delete_income_rule(): void
    {
        $user = User::factory()->create();

        $rule = TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'payroll deposit',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'amount' => null,
            'is_active' => true,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->patch(route('rules.income.update', $rule), ['is_active' => false])
            ->assertRedirect(route('rules.index', ['tab' => 'income']));

        $this->assertFalse($rule->fresh()->is_active);

        $this->actingAs($user)
            ->delete(route('rules.income.destroy', $rule))
            ->assertRedirect(route('rules.index', ['tab' => 'income']));

        $this->assertDatabaseMissing('transaction_classification_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_user_can_bulk_delete_description_only_confirmed_income_rules(): void
    {
        $user = User::factory()->create();

        TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'venmo cashout',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'amount' => null,
            'is_active' => true,
            'metadata' => [],
        ]);

        TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'venmo cashout',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
            'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'amount' => 500.00,
            'is_active' => true,
            'metadata' => [],
        ]);

        TransactionClassificationRule::query()->create([
            'user_id' => $user->id,
            'normalized_pattern' => 'interest payment',
            'classification' => TransactionClassificationRule::CLASSIFICATION_INCOME,
            'direction' => TransactionClassificationRule::DIRECTION_CREDIT,
            'origin' => TransactionClassificationRule::ORIGIN_USER_REJECTED,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'amount' => null,
            'is_active' => true,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->delete(route('rules.income.destroy-description-only'))
            ->assertRedirect(route('rules.index', ['tab' => 'income']));

        $this->assertDatabaseMissing('transaction_classification_rules', [
            'user_id' => $user->id,
            'match_mode' => TransactionClassificationRule::MATCH_DESCRIPTION,
            'origin' => TransactionClassificationRule::ORIGIN_USER_CONFIRMED,
        ]);

        $this->assertDatabaseHas('transaction_classification_rules', [
            'user_id' => $user->id,
            'match_mode' => TransactionClassificationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'amount' => 500.00,
        ]);

        $this->assertDatabaseHas('transaction_classification_rules', [
            'user_id' => $user->id,
            'origin' => TransactionClassificationRule::ORIGIN_USER_REJECTED,
            'normalized_pattern' => 'interest payment',
        ]);
    }

    public function test_legacy_categorization_rules_index_redirects_to_rules_hub(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/categorization-rules')
            ->assertRedirect('/rules?tab=expenses');
    }
}
