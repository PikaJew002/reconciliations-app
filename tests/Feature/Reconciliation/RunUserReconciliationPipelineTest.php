<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\RunUserReconciliationPipeline;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\ReconciliationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunUserReconciliationPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_marks_run_completed_and_reconciles_synthetic_spend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $run = ReconciliationRun::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -12.25,
            'posted_at' => '2026-07-22',
            'card_last_four' => '2525',
            'description' => 'DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525',
            'normalized_description' => 'dbt crd 1232 07/22/26 djsxxusb buc-ee s #0055 richmond ky c#2525',
            'status' => 'unmatched',
        ]);

        (new RunUserReconciliationPipeline($run->id))->handle(
            app(\App\Services\Reconciliation\OrderComponentGenerator::class),
            app(\App\Services\Reconciliation\MerchantMatcher::class),
            app(\App\Services\Reconciliation\OrderPaymentResolutionService::class),
            app(\App\Services\Reconciliation\ReconciliationService::class),
            app(\App\Services\Reconciliation\SyntheticBankSpendReconciler::class),
        );

        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->metadata['merchants_matched']);
        $this->assertSame(1, $run->metadata['synthetic_matched']);
        $this->assertDatabaseHas('merchants', [
            'user_id' => $user->id,
            'normalized_name' => 'buc ee',
        ]);
        $this->assertSame('matched', BankTransaction::query()->first()->status);
    }
}
