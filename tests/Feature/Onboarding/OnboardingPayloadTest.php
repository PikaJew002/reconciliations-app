<?php

namespace Tests\Feature\Onboarding;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\User;
use App\Services\Onboarding\OnboardingSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_do_not_receive_onboarding_props(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding', null));
    }

    public function test_new_user_sees_incomplete_import_steps(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->where('onboarding.finished', false)
                ->where('onboarding.percentage', 0)
                ->has('onboarding.steps', 2)
                ->where('onboarding.steps.0.key', OnboardingSteps::IMPORT_BANK)
                ->where('onboarding.steps.0.complete', false)
                ->where('onboarding.steps.0.href', '/accounts/create')
                ->where('onboarding.steps.0.tour', OnboardingSteps::IMPORT_BANK)
                ->where('onboarding.steps.1.key', OnboardingSteps::IMPORT_ORDERS)
                ->where('onboarding.steps.1.complete', false)
                ->where('onboarding.steps.1.skippable', true)
                ->where('onboarding.steps.1.href', '/orders'));
    }

    public function test_import_bank_href_uses_existing_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->where('onboarding.steps.0.complete', false)
                ->where('onboarding.steps.0.href', "/accounts/{$account->id}/imports"));
    }

    public function test_completed_bank_import_marks_import_bank_complete(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create();
        ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'completed',
            'record_count' => 12,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->where('onboarding.finished', false)
                ->where('onboarding.percentage', 50)
                ->where('onboarding.steps.0.complete', true)
                ->where('onboarding.steps.1.complete', false));
    }

    public function test_bank_transactions_without_completed_batch_still_complete_import_bank(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'processing',
            'record_count' => 0,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.steps.0.complete', true)
                ->where('onboarding.steps.1.complete', false));
    }

    public function test_pending_empty_bank_import_does_not_complete_the_step(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create();
        ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'pending',
            'record_count' => 0,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.steps.0.complete', false));
    }

    public function test_another_users_data_does_not_complete_steps(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $other->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'completed',
            'record_count' => 5,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $other->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);
        Order::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->where('onboarding.steps.0.complete', false)
                ->where('onboarding.steps.1.complete', false));
    }

    public function test_existing_user_with_bank_and_orders_is_auto_hidden(): void
    {
        $user = User::factory()->create();
        $this->seedCompletedBankImport($user);
        Order::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', false)
                ->missing('onboarding.steps'));

        $this->assertNotNull($user->fresh()->onboarding_hidden_at);
    }

    public function test_skipping_orders_after_bank_import_auto_hides_the_panel(): void
    {
        $user = User::factory()->create();
        $this->seedCompletedBankImport($user);

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.skip'), ['step' => OnboardingSteps::IMPORT_ORDERS])
            ->assertRedirect('/');

        $this->assertSame([OnboardingSteps::IMPORT_ORDERS], $user->fresh()->onboarding_skipped_steps);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', false));
    }

    public function test_skipped_orders_are_excluded_while_bank_import_is_still_open(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.skip'), ['step' => OnboardingSteps::IMPORT_ORDERS])
            ->assertRedirect('/');

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->has('onboarding.steps', 1)
                ->where('onboarding.steps.0.key', OnboardingSteps::IMPORT_BANK)
                ->where('onboarding.finished', false));
    }

    public function test_cannot_skip_import_bank(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.skip'), ['step' => OnboardingSteps::IMPORT_BANK])
            ->assertSessionHasErrors('step');
    }

    public function test_guests_cannot_mutate_onboarding(): void
    {
        $this->post(route('onboarding.hide'))->assertRedirect('/login');
        $this->post(route('onboarding.show'))->assertRedirect('/login');
        $this->post(route('onboarding.skip'), ['step' => OnboardingSteps::IMPORT_ORDERS])
            ->assertRedirect('/login');
        $this->post(route('onboarding.tours.update', OnboardingSteps::IMPORT_BANK), [
            'status' => 'dismissed',
        ])->assertRedirect('/login');
    }

    public function test_hide_and_show_toggle_the_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.hide'))
            ->assertRedirect('/');

        $this->assertNotNull($user->fresh()->onboarding_hidden_at);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', false));

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.show'))
            ->assertRedirect('/');

        $this->assertNull($user->fresh()->onboarding_hidden_at);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->has('onboarding.steps', 2));
    }

    public function test_hidden_user_does_not_need_domain_rows_to_receive_hidden_payload(): void
    {
        $user = User::factory()->create([
            'onboarding_hidden_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', false)
                ->missing('onboarding.steps'));
    }

    public function test_tour_complete_and_dismiss_are_persisted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.tours.update', OnboardingSteps::IMPORT_BANK), [
                'status' => 'dismissed',
            ])
            ->assertRedirect('/');

        $this->assertSame(
            [OnboardingSteps::IMPORT_BANK => 'dismissed'],
            $user->fresh()->onboarding_tours,
        );

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.steps.0.tour', null)
                ->where('onboarding.steps.1.tour', OnboardingSteps::IMPORT_ORDERS));

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.tours.update', OnboardingSteps::IMPORT_ORDERS), [
                'status' => 'completed',
            ])
            ->assertRedirect('/');

        $this->assertSame(
            [
                OnboardingSteps::IMPORT_BANK => 'dismissed',
                OnboardingSteps::IMPORT_ORDERS => 'completed',
            ],
            $user->fresh()->onboarding_tours,
        );
    }

    public function test_invalid_tour_key_and_status_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.tours.update', 'not-a-tour'), [
                'status' => 'dismissed',
            ])
            ->assertSessionHasErrors('key');

        $this->actingAs($user)
            ->from('/')
            ->post(route('onboarding.tours.update', OnboardingSteps::IMPORT_BANK), [
                'status' => 'skipped',
            ])
            ->assertSessionHasErrors('status');
    }

    public function test_completed_amazon_import_completes_orders_step(): void
    {
        $user = User::factory()->create();
        ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'amazon',
            'type' => 'orders',
            'status' => 'completed',
            'record_count' => 3,
        ]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('onboarding.visible', true)
                ->where('onboarding.steps.0.complete', false)
                ->where('onboarding.steps.1.complete', true));
    }

    protected function seedCompletedBankImport(User $user): void
    {
        $account = Account::factory()->for($user)->create();
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'completed',
            'record_count' => 8,
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
        ]);
    }
}
