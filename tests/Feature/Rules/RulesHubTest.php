<?php

namespace Tests\Feature\Rules;

use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\TransactionCategorizationRule;
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
        $incomeCategory = Category::factory()->for($user)->income()->create(['name' => 'Salary']);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => 'venmo cashout',
            'amount' => 500.00,
            'is_active' => true,
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
                ->where('incomeRules.0.category.name', 'Salary')
                ->has('expenseRules', 1)
                ->where('expenseRules.0.category.name', 'Dining')
            );
    }

    public function test_user_can_toggle_and_delete_income_rule(): void
    {
        $user = User::factory()->create();

        $rule = TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => null,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'merchant_id' => null,
            'normalized_pattern' => 'payroll deposit',
            'amount' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('rules.income.update', $rule), ['is_active' => false])
            ->assertRedirect(route('rules.index', ['tab' => 'income']));

        $this->assertFalse($rule->fresh()->is_active);

        $this->actingAs($user)
            ->delete(route('rules.income.destroy', $rule))
            ->assertRedirect(route('rules.index', ['tab' => 'income']));

        $this->assertDatabaseMissing('transaction_categorization_rules', [
            'id' => $rule->id,
        ]);
    }

    public function test_user_can_bulk_delete_description_only_income_rules(): void
    {
        $user = User::factory()->create();

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => null,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'merchant_id' => null,
            'normalized_pattern' => 'venmo cashout',
            'amount' => null,
            'is_active' => true,
        ]);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => null,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'merchant_id' => null,
            'normalized_pattern' => 'venmo cashout',
            'amount' => 500.00,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('rules.income.destroy-description-only'))
            ->assertRedirect(route('rules.index', ['tab' => 'income']));

        $this->assertDatabaseMissing('transaction_categorization_rules', [
            'user_id' => $user->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
        ]);

        $this->assertDatabaseHas('transaction_categorization_rules', [
            'user_id' => $user->id,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'match_mode' => TransactionCategorizationRule::MATCH_EXACT_DESCRIPTION_AND_AMOUNT,
            'amount' => 500.00,
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
