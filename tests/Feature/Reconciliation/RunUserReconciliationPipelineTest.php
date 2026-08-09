<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\RunUserReconciliationPipeline;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\ReconciliationRun;
use App\Models\TransactionCategorizationRule;
use App\Models\User;
use App\Services\Reconciliation\CreditCardPaymentPairingService;
use App\Services\Reconciliation\IncomeClassificationService;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\OrderComponentGenerator;
use App\Services\Reconciliation\OrderPaymentResolutionService;
use App\Services\Reconciliation\ReconciliationService;
use App\Services\Reconciliation\TransactionCategorizationService;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunUserReconciliationPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_marks_run_completed_and_applies_categorization_rules(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => "Buc-ee's",
            'normalized_name' => 'buc ee',
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create(['name' => 'Travel']);

        TransactionCategorizationRule::factory()->create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'match_mode' => TransactionCategorizationRule::MATCH_MERCHANT,
            'merchant_id' => $merchant->id,
            'normalized_pattern' => null,
            'amount' => null,
            'is_active' => true,
        ]);

        $run = ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -12.25,
            'posted_at' => '2026-07-22',
            'card_last_four' => '2525',
            'description' => 'BUC-EE S #0055',
            'normalized_description' => 'buc-ee s #0055',
            'status' => 'unmatched',
        ]);

        (new RunUserReconciliationPipeline($run->id))->handle(
            app(CreditCardPaymentPairingService::class),
            app(TransferPairingService::class),
            app(IncomeClassificationService::class),
            app(TransactionCategorizationService::class),
            app(OrderComponentGenerator::class),
            app(MerchantMatcher::class),
            app(OrderPaymentResolutionService::class),
            app(ReconciliationService::class),
        );

        $run->refresh();
        $transaction = BankTransaction::query()->first();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['transactions_categorized']);
        $this->assertArrayNotHasKey('synthetic_matched', $run->metadata ?? []);
        $this->assertSame('ignored', $transaction->status);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $transaction->classification);
        $this->assertSame($category->id, $transaction->category_id);
    }
}
