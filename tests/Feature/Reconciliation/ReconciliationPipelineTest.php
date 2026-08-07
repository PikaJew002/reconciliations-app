<?php

namespace Tests\Feature\Reconciliation;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReconciliationPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_import_and_reconciliation_pipeline(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);

        $walmartPath = 'imports/walmart-reconcile.json';
        Storage::disk('local')->put($walmartPath, json_encode([
            [
                'orderNumber' => '70188890864903553777',
                'orderDate' => 'Jul 25, 2026 purchase',
                'orderSubtotal' => '$71.77',
                'orderTotal' => '$71.98',
                'deliveryCharges' => '$0.00',
                'tax' => '$0.21',
                'tip' => '$0.00',
                'savings' => '',
                'deliveredDate' => '',
                'paymentMethods' => 'Mastercard ending in 2195',
                'paymentMethodDetails' => [
                    [
                        'ending' => 'Mastercard ending in 2195',
                        'amount' => '',
                    ],
                ],
                'items' => [
                    [
                        'productName' => 'Klondike Ice Cream',
                        'quantity' => '1',
                        'price' => '$71.77',
                        'usItemId' => '10801688',
                    ],
                ],
            ],
        ]));

        $bankPath = 'imports/bank-reconcile.csv';
        Storage::disk('local')->put($bankPath, <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,2026-07-27,"POS DEB 1848 07/25/26 00061542 WM SUPERCENTER #1190 120 JILL DR BEREA         KY C#2195",,Debit,71.98
CSV);

        $walmartBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'storage_path' => $walmartPath,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => [],
        ]);

        $bankBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $bankPath,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($walmartBatch))->handle(app(\App\Services\Imports\ImporterResolver::class));
        (new ProcessImportBatch($bankBatch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $order = Order::query()->first();
        $transaction = BankTransaction::query()->first();

        $this->assertNotNull($order);
        $this->assertSame('2195', $order->payment_last_four);
        $this->assertSame('2195', $transaction->card_last_four);
        $this->assertSame('walmart', $order->merchant->normalized_name);
        $this->assertSame($order->merchant_id, $transaction->merchant_id);

        $this->assertGreaterThan(0, OrderComponent::query()->where('order_id', $order->id)->count());
        $this->assertSame('matched', $transaction->status);
        $this->assertSame('reconciled', $order->fresh()->status);
        $this->assertGreaterThan(0, TransactionAllocation::query()->count());
        $this->assertEqualsWithDelta(71.98, (float) $transaction->allocated_amount, 0.01);
    }
}
