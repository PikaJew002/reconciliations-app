<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessImportBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_imports_cumberland_valley_bank_transactions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $path = 'imports/test.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,4/30/26,TRANSFER FROM X1758 TO X6218  LEFTOVER 4-30-26,,Credit,213.11
Joint Account 2,4/30/26,POS DEB 1716 04/29/26 40269900 WAL-MART #1190 120 JILL DR BEREA         KY C#2195,,Debit,6.75
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->record_count);
        $this->assertNotNull($batch->completed_at);

        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertCount(2, $transactions);
        $this->assertSame('2026-04-30', $transactions[0]->posted_at->toDateString());
        $this->assertSame('213.11', $transactions[0]->amount);
        $this->assertNull($transactions[0]->transaction_date);
        $this->assertNotEmpty($transactions[0]->external_id);
        $this->assertSame('2026-04-30', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-04-29', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('-6.75', $transactions[1]->amount);
        $this->assertNotEmpty($transactions[1]->external_id);
        $this->assertNotSame($transactions[0]->external_id, $transactions[1]->external_id);
    }

    public function test_job_imports_cumberland_valley_iso_date_exports(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $path = 'imports/iso-dates.csv';

        Storage::disk('local')->put($path, <<<'CSV'
"Account Name","Processed Date","Description","Check Number","Credit or Debit","Amount"
"Joint Account 2",2026-07-31,"TRANSFER FROM X1758 TO X6218  LEFTOVER 7-30-26",,"Credit",224.54
"Joint Account 2",2026-07-30,"POS DEB 2116 07/28/26 21812180 WALMART.COM WALMART.COM BENTONVILLE   AR C#2525",,"Debit",199.33
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();
        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->record_count);
        $this->assertSame('2026-07-31', $transactions[0]->posted_at->toDateString());
        $this->assertSame('224.54', $transactions[0]->amount);
        $this->assertSame('2026-07-30', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-07-28', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('-199.33', $transactions[1]->amount);
    }

    public function test_job_skips_duplicate_bank_transactions_on_reimport(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $csv = <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,4/30/26,TRANSFER FROM X1758 TO X6218  LEFTOVER 4-30-26,,Credit,213.11
CSV;

        $firstPath = 'imports/first.csv';
        $secondPath = 'imports/second.csv';
        Storage::disk('local')->put($firstPath, $csv);
        Storage::disk('local')->put($secondPath, $csv);

        $firstBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $firstPath,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        $secondBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $secondPath,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['account_id' => $account->id],
        ]);

        $resolver = app(\App\Services\Imports\ImporterResolver::class);

        (new ProcessImportBatch($firstBatch))->handle($resolver);
        (new ProcessImportBatch($secondBatch))->handle($resolver);

        $firstBatch->refresh();
        $secondBatch->refresh();

        $this->assertSame('completed', $firstBatch->status);
        $this->assertSame(1, $firstBatch->record_count);
        $this->assertSame('completed', $secondBatch->status);
        $this->assertSame(0, $secondBatch->record_count);
        $this->assertSame(1, BankTransaction::query()->count());
    }

    public function test_job_marks_batch_failed_when_account_id_is_missing(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/test.csv';

        Storage::disk('local')->put($path, "Date,Description,Amount\n01/01/2026,WALMART,-12.34\n");

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('failed', $batch->status);
        $this->assertNotNull($batch->error_message);
    }

    public function test_job_imports_walmart_orders_from_json(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/walmart-orders.json';

        Storage::disk('local')->put($path, json_encode([
            [
                'schemaVersion' => 3,
                'orderNumber' => '70188890864903553777',
                'orderDate' => 'Jul 25, 2026 purchase',
                'orderSubtotal' => '$71.77',
                'orderTotal' => '$71.98',
                'deliveryCharges' => '$0.00',
                'tax' => '$0.21',
                'tip' => '$0.00',
                'savings' => '',
                'deliveredDate' => '',
                'items' => [
                    [
                        'productName' => 'Klondike Reese\'s Peanut Butter Ice Cream Sandwiches Frozen Desserts, 6 Count',
                        'quantity' => '1',
                        'price' => '$3.78',
                        'usItemId' => '10801688',
                    ],
                    [
                        'productName' => 'State Fair 100% Beef Corn Dogs, 42.7 oz, 16 Count (Frozen)',
                        'quantity' => '2',
                        'price' => '$27.24',
                        'usItemId' => '46629645',
                    ],
                ],
            ],
        ]));

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->record_count);
        $this->assertNotNull($batch->completed_at);

        $order = Order::query()->first();

        $this->assertNotNull($order);
        $this->assertSame('70188890864903553777', $order->order_number);
        $this->assertSame('2026-07-25', $order->ordered_at->toDateString());
        $this->assertSame('71.77', $order->subtotal);
        $this->assertSame('0.21', $order->tax);
        $this->assertSame('71.98', $order->total);
        $this->assertSame('walmart', $order->merchant->normalized_name);

        $items = OrderItem::query()->orderBy('line_number')->get();

        $this->assertCount(2, $items);
        $this->assertSame(1, $items[0]->line_number);
        $this->assertSame('10801688', $items[0]->sku);
        $this->assertSame('3.78', $items[0]->unit_price);
        $this->assertSame('3.78', $items[0]->extended_price);
        $this->assertSame(2, $items[1]->line_number);
        $this->assertSame('13.62', $items[1]->unit_price);
        $this->assertSame('27.24', $items[1]->extended_price);
    }

    public function test_job_imports_multiple_walmart_orders_from_json_array(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/walmart-orders-multi.json';

        Storage::disk('local')->put($path, json_encode([
            [
                'orderNumber' => '70188890864903553777',
                'orderDate' => 'Jul 25, 2026 purchase',
                'orderSubtotal' => '$71.77',
                'orderTotal' => '$71.98',
                'tax' => '$0.21',
                'items' => [
                    [
                        'productName' => 'Klondike Reese\'s Peanut Butter Ice Cream Sandwiches Frozen Desserts, 6 Count',
                        'quantity' => '1',
                        'price' => '$3.78',
                        'usItemId' => '10801688',
                    ],
                ],
            ],
            [
                'orderNumber' => '200013093198585',
                'orderDate' => 'Jul 20, 2026 purchase',
                'orderSubtotal' => '$24.50',
                'orderTotal' => '$26.12',
                'tax' => '$1.62',
                'items' => [
                    [
                        'productName' => 'Great Value Whole Milk, 1 Gallon',
                        'quantity' => '1',
                        'price' => '$3.24',
                        'usItemId' => '10450114',
                    ],
                    [
                        'productName' => 'Bananas, each',
                        'quantity' => '6',
                        'price' => '$1.74',
                        'usItemId' => '443909',
                    ],
                ],
            ],
        ]));

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        (new ProcessImportBatch($batch))->handle(app(\App\Services\Imports\ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->record_count);

        $orders = Order::query()->orderBy('order_number')->get();

        $this->assertCount(2, $orders);
        $this->assertSame('200013093198585', $orders[0]->order_number);
        $this->assertSame('26.12', $orders[0]->total);
        $this->assertSame('70188890864903553777', $orders[1]->order_number);
        $this->assertSame(3, OrderItem::query()->count());
        $this->assertSame(2, OrderItem::query()->where('order_id', $orders[0]->id)->count());
        $this->assertSame(1, OrderItem::query()->where('order_id', $orders[1]->id)->count());
    }

    public function test_job_skips_duplicate_walmart_orders_on_reimport(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $json = json_encode([
            [
                'orderNumber' => '70188890864903553777',
                'orderDate' => 'Jul 25, 2026 purchase',
                'orderSubtotal' => '$71.77',
                'orderTotal' => '$71.98',
                'tax' => '$0.21',
                'items' => [
                    [
                        'productName' => 'Klondike Reese\'s Peanut Butter Ice Cream Sandwiches Frozen Desserts, 6 Count',
                        'quantity' => '1',
                        'price' => '$3.78',
                        'usItemId' => '10801688',
                    ],
                ],
            ],
        ]);

        $firstPath = 'imports/walmart-first.json';
        $secondPath = 'imports/walmart-second.json';
        Storage::disk('local')->put($firstPath, $json);
        Storage::disk('local')->put($secondPath, $json);

        $firstBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'storage_path' => $firstPath,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        $secondBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'storage_path' => $secondPath,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        $resolver = app(\App\Services\Imports\ImporterResolver::class);

        (new ProcessImportBatch($firstBatch))->handle($resolver);
        (new ProcessImportBatch($secondBatch))->handle($resolver);

        $firstBatch->refresh();
        $secondBatch->refresh();

        $this->assertSame('completed', $firstBatch->status);
        $this->assertSame(1, $firstBatch->record_count);
        $this->assertSame('completed', $secondBatch->status);
        $this->assertSame(0, $secondBatch->record_count);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, OrderItem::query()->count());
    }
}
