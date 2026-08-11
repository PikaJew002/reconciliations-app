<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\CategorizeTransactions;
use App\Jobs\GenerateOrderComponents;
use App\Jobs\MatchMerchants;
use App\Jobs\PairCreditCardPayments;
use App\Jobs\PairTransfers;
use App\Jobs\ProcessImportBatch;
use App\Jobs\RunReconciliation;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Imports\ImporterResolver;
use App\Services\Reconciliation\MerchantMatcher;
use App\Services\Reconciliation\SyntheticBankSpendReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyntheticBankSpendReconcilerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_synthetic_order_component_and_allocation_for_pos_spend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Buc Ee',
            'normalized_name' => 'buc ee',
            'supports_order_import' => false,
            'type' => Merchant::OTHER,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'posted_at' => '2026-07-22',
            'amount' => -12.25,
            'card_last_four' => '2525',
            'description' => 'DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525',
            'normalized_description' => 'dbt crd 1232 07/22/26 djsxxusb buc-ee s #0055 richmond ky c#2525',
            'status' => 'unmatched',
        ]);

        $count = app(SyntheticBankSpendReconciler::class)->reconcileForUser($user->id);

        $this->assertSame(1, $count);

        $transaction->refresh();
        $this->assertSame('matched', $transaction->status);

        $order = Order::query()->where('merchant_id', $merchant->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('reconciled', $order->status);
        $this->assertSame('SYN-BTX-'.$transaction->id, $order->order_number);
        $this->assertEquals(12.25, (float) $order->total);
        $this->assertSame('bank_synthetic', $order->metadata['source']);
        $this->assertSame($transaction->id, $order->metadata['bank_transaction_id']);
        $this->assertSame('2525', $order->payment_last_four);

        $component = OrderComponent::query()->where('order_id', $order->id)->sole();
        $this->assertSame('product', $component->type);
        $this->assertSame('Buc Ee', $component->description);
        $this->assertEquals(12.25, (float) $component->amount);
        $this->assertNull($component->category_id);

        $allocation = TransactionAllocation::query()->where('bank_transaction_id', $transaction->id)->sole();
        $this->assertSame($component->id, $allocation->order_component_id);
        $this->assertEquals(12.25, (float) $allocation->allocated_amount);
        $this->assertSame('automatic', $allocation->allocation_type);
    }

    public function test_does_not_synthesize_for_walmart_import_backed_merchants(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
            'supports_order_import' => true,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -33.20,
            'description' => 'DBT CRD WALMART.COM',
            'normalized_description' => 'dbt crd walmart.com',
            'status' => 'unmatched',
        ]);

        $count = app(SyntheticBankSpendReconciler::class)->reconcileForUser($user->id);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('transaction_allocations', 0);
        $this->assertSame('unmatched', BankTransaction::query()->first()->status);
    }

    public function test_leaves_amazon_untouched_through_match_and_synthetic_flow(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -19.99,
            'description' => 'DBT CRD 0848 07/22/26 DJJKQM32 AMAZON.COM*SEATTLE WA C#2195',
            'normalized_description' => 'dbt crd 0848 07/22/26 djjkqm32 amazon.com*seattle wa c#2195',
            'status' => 'unmatched',
        ]);

        app(MerchantMatcher::class)->matchForUser($user->id);
        $count = app(SyntheticBankSpendReconciler::class)->reconcileForUser($user->id);

        $transaction->refresh();
        $this->assertSame(0, $count);
        $this->assertNull($transaction->merchant_id);
        $this->assertSame('unmatched', $transaction->status);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'speedway',
            'supports_order_import' => false,
        ]);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -36.91,
            'status' => 'unmatched',
        ]);

        $reconciler = app(SyntheticBankSpendReconciler::class);

        $this->assertSame(1, $reconciler->reconcileForUser($user->id));
        $this->assertSame(0, $reconciler->reconcileForUser($user->id));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('transaction_allocations', 1);
    }

    public function test_process_import_batch_chains_synthetic_reconcile_job(): void
    {
        Storage::fake('local');
        Bus::fake();

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $path = 'imports/chain-check.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,2026-07-22,"DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525",,Debit,12.25
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        Bus::assertChained([
            PairCreditCardPayments::class,
            PairTransfers::class,
            CategorizeTransactions::class,
            GenerateOrderComponents::class,
            MatchMerchants::class,
            RunReconciliation::class,
        ]);
    }

    public function test_match_and_synthesize_capital_one_spend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'posted_at' => '2026-07-15',
            'amount' => -27.12,
            'card_last_four' => '5394',
            'description' => 'TACO BELL 021543',
            'normalized_description' => 'taco bell 021543',
            'status' => 'unmatched',
        ]);

        app(MerchantMatcher::class)->matchForUser($user->id);
        $count = app(SyntheticBankSpendReconciler::class)->reconcileForUser($user->id);

        $transaction->refresh();

        $this->assertSame(1, $count);
        $this->assertSame('matched', $transaction->status);
        $this->assertNotNull($transaction->merchant_id);

        $order = Order::query()->where('merchant_id', $transaction->merchant_id)->first();

        $this->assertNotNull($order);
        $this->assertSame('bank_synthetic', $order->metadata['source']);
        $this->assertSame($transaction->id, $order->metadata['bank_transaction_id']);
        $this->assertEquals(27.12, (float) $order->total);
        $this->assertSame('5394', $order->payment_last_four);
        $this->assertSame('taco bell', $transaction->merchant->normalized_name);
    }
}
