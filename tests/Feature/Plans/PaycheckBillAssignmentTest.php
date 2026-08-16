<?php

namespace Tests\Feature\Plans;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaycheckBillAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_assigning_bills_to_a_paycheck_updates_index_payload_and_leftover(): void
    {
        [$user, $paycheck, $rent, $electric] = $this->assignmentSetup();

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $paycheck), [
                'bill_template_ids' => [$rent->id, $electric->id],
                'month' => '2026-03',
            ])
            ->assertRedirect(route('plans.index', ['month' => '2026-03']));

        $this->assertDatabaseHas('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
            'bill_template_id' => $rent->id,
        ]);
        $this->assertDatabaseHas('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
            'bill_template_id' => $electric->id,
        ]);

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->has('paycheck_templates', 1)
                ->where('paycheck_templates.0.leftover', 1660)
                ->where('paycheck_templates.0.assigned_bill_ids', $this->sortedIds([$rent->id, $electric->id]))
                ->where('bill_templates.0.assigned_paycheck.id', $paycheck->id)
                ->where('bill_templates.0.assigned_paycheck.name', 'Acme paycheck')
                ->where('bill_templates.1.assigned_paycheck.id', $paycheck->id));
    }

    public function test_replacing_assignments_unassigns_omitted_bills(): void
    {
        [$user, $paycheck, $rent, $electric] = $this->assignmentSetup();

        $paycheck->assignedBills()->sync([$rent->id, $electric->id]);

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $paycheck), [
                'bill_template_ids' => [$electric->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
            'bill_template_id' => $rent->id,
        ]);
        $this->assertDatabaseHas('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
            'bill_template_id' => $electric->id,
        ]);
    }

    public function test_bill_cannot_be_assigned_to_two_paychecks(): void
    {
        [$user, $paycheck, $rent] = $this->assignmentSetup();
        $second = $this->secondPaycheck($user, $paycheck->category_id);

        $paycheck->assignedBills()->sync([$rent->id]);

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $second), [
                'bill_template_ids' => [$rent->id],
            ])
            ->assertSessionHasErrors('bill_template_ids');

        $this->assertDatabaseHas('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
            'bill_template_id' => $rent->id,
        ]);
        $this->assertDatabaseMissing('planned_template_assignments', [
            'paycheck_template_id' => $second->id,
            'bill_template_id' => $rent->id,
        ]);
    }

    public function test_bill_can_move_after_being_removed_from_the_first_paycheck(): void
    {
        [$user, $paycheck, $rent] = $this->assignmentSetup();
        $second = $this->secondPaycheck($user, $paycheck->category_id);

        $paycheck->assignedBills()->sync([$rent->id]);

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $paycheck), [
                'bill_template_ids' => [],
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $second), [
                'bill_template_ids' => [$rent->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('planned_template_assignments', [
            'paycheck_template_id' => $second->id,
            'bill_template_id' => $rent->id,
        ]);
        $this->assertDatabaseMissing('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
            'bill_template_id' => $rent->id,
        ]);
    }

    public function test_inactive_assigned_bill_is_excluded_from_leftover(): void
    {
        [$user, $paycheck, $rent, $electric] = $this->assignmentSetup();
        $electric->update(['is_active' => false]);
        $paycheck->assignedBills()->sync([$rent->id, $electric->id]);

        $this->actingAs($user)
            ->get('/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where('paycheck_templates.0.leftover', 1800)
                ->where('paycheck_templates.0.assigned_bill_ids', $this->sortedIds([$rent->id, $electric->id])));
    }

    public function test_deleting_a_bill_template_removes_the_assignment(): void
    {
        [$user, $paycheck, $rent] = $this->assignmentSetup();
        $paycheck->assignedBills()->sync([$rent->id]);

        $this->actingAs($user)
            ->delete(route('plans.destroy', $rent))
            ->assertRedirect(route('plans.index'));

        $this->assertDatabaseMissing('planned_template_assignments', [
            'bill_template_id' => $rent->id,
        ]);
        $this->assertDatabaseMissing('planned_templates', [
            'id' => $rent->id,
        ]);
    }

    public function test_non_owner_cannot_update_assignments(): void
    {
        [$user, $paycheck] = $this->assignmentSetup();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->put(route('plans.assignments.update', $paycheck), [
                'bill_template_ids' => [],
            ])
            ->assertNotFound();
    }

    public function test_bill_template_cannot_be_the_assignment_target(): void
    {
        [$user, $paycheck, $rent] = $this->assignmentSetup();

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $rent), [
                'bill_template_ids' => [],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('planned_template_assignments', [
            'paycheck_template_id' => $paycheck->id,
        ]);
    }

    public function test_foreign_bill_cannot_be_assigned(): void
    {
        [$user, $paycheck] = $this->assignmentSetup();
        $other = User::factory()->create();
        $otherBill = Category::factory()->for($other)->bill()->create();
        $foreignBill = PlannedTemplate::factory()->bill()->create([
            'user_id' => $other->id,
            'category_id' => $otherBill->id,
        ]);

        $this->actingAs($user)
            ->put(route('plans.assignments.update', $paycheck), [
                'bill_template_ids' => [$foreignBill->id],
            ])
            ->assertSessionHasErrors('bill_template_ids');
    }

    public function test_paycheck_occurrence_leftover_uses_expected_amounts_until_resolved(): void
    {
        [$user, $paycheck, $rent, $electric] = $this->assignmentSetup();
        $paycheck->assignedBills()->sync([$rent->id, $electric->id]);

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where(
                    'paycheck_occurrences',
                    fn ($occurrences) => collect($occurrences)->contains(
                        fn ($occurrence) => $occurrence['expected_date'] === '2026-03-01'
                            && (float) $occurrence['leftover'] === 1660.0,
                    ),
                ));
    }

    public function test_paycheck_occurrence_leftover_uses_actuals_when_resolved(): void
    {
        [$user, $paycheck, $rent, $electric] = $this->assignmentSetup();
        $paycheck->assignedBills()->sync([$rent->id, $electric->id]);

        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->get('/plans?month=2026-03');

        $paycheckOccurrence = PlannedOccurrence::query()
            ->where('template_id', $paycheck->id)
            ->whereDate('expected_date', '2026-03-01')
            ->firstOrFail();
        $rentOccurrence = PlannedOccurrence::query()
            ->where('template_id', $rent->id)
            ->whereDate('expected_date', '2026-03-01')
            ->firstOrFail();
        $electricOccurrence = PlannedOccurrence::query()
            ->where('template_id', $electric->id)
            ->whereDate('expected_date', '2026-03-05')
            ->firstOrFail();

        $paycheckTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => 2987.0,
            'classification' => BankTransaction::CLASSIFICATION_INCOME,
            'posted_at' => '2026-03-01',
        ]);
        $rentTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -1200.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'posted_at' => '2026-03-01',
        ]);
        $electricTx = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -135.0,
            'classification' => BankTransaction::CLASSIFICATION_BILL,
            'posted_at' => '2026-03-05',
        ]);

        $paycheckOccurrence->update([
            'bank_transaction_id' => $paycheckTx->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
        ]);
        $rentOccurrence->update([
            'bank_transaction_id' => $rentTx->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
        ]);
        $electricOccurrence->update([
            'bank_transaction_id' => $electricTx->id,
            'status' => PlannedOccurrence::STATUS_RESOLVED,
        ]);

        $this->actingAs($user)
            ->get('/plans?month=2026-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plans/Index')
                ->where(
                    'paycheck_occurrences',
                    fn ($occurrences) => collect($occurrences)->contains(
                        fn ($occurrence) => $occurrence['expected_date'] === '2026-03-01'
                            && (float) $occurrence['amount'] === 2987.0
                            && (float) $occurrence['leftover'] === 1652.0,
                    ),
                ));
    }

    /**
     * @return array{0: User, 1: PlannedTemplate, 2: PlannedTemplate, 3: PlannedTemplate}
     */
    protected function assignmentSetup(): array
    {
        $user = User::factory()->create();
        $salary = Category::factory()->for($user)->income()->create(['name' => 'Salary']);
        $housing = Category::factory()->for($user)->bill()->create(['name' => 'Housing']);
        $utilities = Category::factory()->for($user)->bill()->create(['name' => 'Utilities']);

        $paycheck = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'name' => 'Acme paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 1,
            'expected_amount' => 3000,
        ]);
        $rent = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $housing->id,
            'name' => 'Rent',
            'expected_day' => 1,
            'expected_amount' => 1200,
            'amount' => 1200,
        ]);
        $electric = PlannedTemplate::factory()->bill()->create([
            'user_id' => $user->id,
            'category_id' => $utilities->id,
            'name' => 'Electric',
            'expected_day' => 5,
            'expected_amount' => 140,
            'amount' => 140,
        ]);

        return [$user, $paycheck, $rent, $electric];
    }

    protected function secondPaycheck(User $user, int $categoryId): PlannedTemplate
    {
        return PlannedTemplate::factory()->create([
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'name' => 'Mid-month paycheck',
            'match_mode' => TransactionCategorizationRule::MATCH_DESCRIPTION,
            'normalized_pattern' => 'acme payroll',
            'expected_day' => 15,
            'expected_amount' => 3000,
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    protected function sortedIds(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }
}
