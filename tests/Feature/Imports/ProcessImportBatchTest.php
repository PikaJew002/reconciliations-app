<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\VenmoActivity;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Imports\ImporterResolver;
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
            'user_id' => $user->id,
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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

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
        $this->assertSame('2195', $transactions[1]->card_last_four);
        $this->assertNotEmpty($transactions[1]->external_id);
        $this->assertNotSame($transactions[0]->external_id, $transactions[1]->external_id);
    }

    public function test_job_imports_cumberland_valley_iso_date_exports(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();
        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->record_count);
        $this->assertSame('2026-07-31', $transactions[0]->posted_at->toDateString());
        $this->assertSame('224.54', $transactions[0]->amount);
        $this->assertSame('2026-07-30', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-07-28', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('-199.33', $transactions[1]->amount);
        $this->assertSame('2525', $transactions[1]->card_last_four);
    }

    public function test_job_imports_cumberland_valley_tab_separated_txt_exports(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
        ]);
        $path = 'imports/cvnb-transactions.txt';

        $tsv = implode("\n", [
            implode("\t", ['"Account Name"', '"Processed Date"', '"Description"', '"Check Number"', '"Credit or Debit"', '"Amount"']),
            implode("\t", ['"Joint Account 1"', '2026-08-14', '"TRANSFER FROM X1758 TO X6218  LEFTOVER 8-14-26"', '', '"Debit"', '502.59']),
            implode("\t", ['"Joint Account 1"', '2026-08-14', '"POS DEB 1942 08/13/26 00469841 WENDYS 706 104 PRINCE ROYAL D BEREA         KY C#0975"', '', '"Debit"', '2.61']),
            implode("\t", ['"Joint Account 1"', '2026-08-14', '"PAYROLL    KCTCS DIR DEP PPD"', '', '"Credit"', '2253.76']),
            implode("\t", ['"Joint Account 1"', '2026-07-23', '"CHECK 654"', '654', '"Debit"', '260.00']),
        ])."\n";

        Storage::disk('local')->put($path, $tsv);

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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();
        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(4, $batch->record_count);
        $this->assertSame('2026-08-14', $transactions[0]->posted_at->toDateString());
        $this->assertSame('-502.59', $transactions[0]->amount);
        $this->assertSame('2026-08-14', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-08-13', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('-2.61', $transactions[1]->amount);
        $this->assertSame('0975', $transactions[1]->card_last_four);
        $this->assertSame('2253.76', $transactions[2]->amount);
        $this->assertSame('CHECK 654', $transactions[3]->description);
        $this->assertSame('-260.00', $transactions[3]->amount);
    }

    public function test_job_imports_capital_one_credit_card_transactions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $path = 'imports/capital-one.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Transaction Date,Posted Date,Card No.,Description,Category,Debit,Credit
2026-07-13,2026-07-15,5394,TACO BELL 021543,Dining,27.12,
2026-07-10,2026-07-10,5394,CAPITAL ONE MOBILE PYMT,Payment/Credit,,349.85
2026-07-08,2026-07-09,5394,WAL-MART #1190,Merchandise,41.38,
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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->record_count);
        $this->assertNotNull($batch->completed_at);

        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertCount(3, $transactions);

        $this->assertSame('2026-07-15', $transactions[0]->posted_at->toDateString());
        $this->assertSame('2026-07-13', $transactions[0]->transaction_date->toDateString());
        $this->assertSame('-27.12', $transactions[0]->amount);
        $this->assertSame('5394', $transactions[0]->card_last_four);
        $this->assertSame('TACO BELL 021543', $transactions[0]->description);
        $this->assertNotEmpty($transactions[0]->external_id);

        $this->assertSame('2026-07-10', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-07-10', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('349.85', $transactions[1]->amount);
        $this->assertSame('CAPITAL ONE MOBILE PYMT', $transactions[1]->description);

        $this->assertSame('2026-07-09', $transactions[2]->posted_at->toDateString());
        $this->assertSame('2026-07-08', $transactions[2]->transaction_date->toDateString());
        $this->assertSame('-41.38', $transactions[2]->amount);
        $this->assertSame('WAL-MART #1190', $transactions[2]->description);
    }

    public function test_job_imports_cumberland_valley_credit_card_transactions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CumberlandValleyCreditCardTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CREDIT_CARD,
        ]);
        $path = 'imports/cvnb-credit-card.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Account Number,Cardholder Name,Trans Date,Posting Date,Type,Category,Merchant Name,Merchant City,Merchant State,Amount,Reference Number,Tran Type,MCC Code,MCC Description,
"XXXX-7067","AARON JOSEPH EISENBERG","06/04/2026","06/05/2026","Debit","Auto Related","SPEEDWAY 44090           ","LEXINGTON    ","KY ","$28.56","02305376156000584360642	","Purchase","5542","Automated Gasoline Dispensers",
"XXXX-7067","AARON JOSEPH EISENBERG","06/01/2026","06/01/2026","Credit","Payments and Fees","INTERNET PMT-THANK YOU   ","TAMPA        ","   ","($150.00)","99988877766655544433322	","Payment","6010","Financial Institutions - Banks Savings",
"XXXX-7067","AARON JOSEPH EISENBERG","06/03/2026","06/04/2026","Debit","Groceries","WAL-MART #1190           ","BEREA        ","KY ","$18.06","55483826155025056839598	","Purchase","5411","Grocery Stores Supermarkets",
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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->record_count);
        $this->assertNotNull($batch->completed_at);

        $transactions = BankTransaction::query()->orderBy('id')->get();

        $this->assertCount(3, $transactions);

        $this->assertSame('2026-06-05', $transactions[0]->posted_at->toDateString());
        $this->assertSame('2026-06-04', $transactions[0]->transaction_date->toDateString());
        $this->assertSame('-28.56', $transactions[0]->amount);
        $this->assertSame('7067', $transactions[0]->card_last_four);
        $this->assertSame('SPEEDWAY 44090', $transactions[0]->description);
        $this->assertSame('02305376156000584360642', $transactions[0]->external_id);

        $this->assertSame('2026-06-01', $transactions[1]->posted_at->toDateString());
        $this->assertSame('2026-06-01', $transactions[1]->transaction_date->toDateString());
        $this->assertSame('150.00', $transactions[1]->amount);
        $this->assertSame('INTERNET PMT-THANK YOU', $transactions[1]->description);
        $this->assertSame('99988877766655544433322', $transactions[1]->external_id);

        $this->assertSame('2026-06-04', $transactions[2]->posted_at->toDateString());
        $this->assertSame('2026-06-03', $transactions[2]->transaction_date->toDateString());
        $this->assertSame('-18.06', $transactions[2]->amount);
        $this->assertSame('WAL-MART #1190', $transactions[2]->description);
        $this->assertSame('55483826155025056839598', $transactions[2]->external_id);
    }

    public function test_job_skips_duplicate_bank_transactions_on_reimport(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
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

        $resolver = app(ImporterResolver::class);

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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

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
                'savings' => '$5.03',
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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

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
        $this->assertSame('0.00', $order->discount);
        $this->assertSame('71.98', $order->total);
        $this->assertSame('2195', $order->payment_last_four);
        $this->assertSame('walmart', $order->merchant->normalized_name);
        $this->assertSame('$5.03', $order->metadata['savings'] ?? null);

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

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

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

        $resolver = app(ImporterResolver::class);

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

    public function test_job_imports_venmo_statement_rows_and_skips_header_footer(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/venmo.csv';

        Storage::disk('local')->put($path, <<<'CSV'
Account Statement - (@Aaron-Eisenberg-7) ,,,,,,,,,,,,,,,,,,,,,
Account Activity,,,,,,,,,,,,,,,,,,,,,
,ID,Datetime,Type,Status,Note,From,To,Amount (total),Amount (tip),Amount (tax),Amount (fee),Tax Rate,Tax Exempt,Funding Source,Destination,Beginning Balance,Ending Balance,Statement Period Venmo Fees,Terminal Location,Year to Date Venmo Fees,Disclaimer
,,,,,,,,,,,,,,,,$0.00,,,,,
,4613052433140029613,2026-06-05T19:11:43,Payment,Complete,Extreme,Aaron Eisenberg,Tyler Adams,- $250.00,,0,,0,,Mastercard *2195,,,,,Venmo,,
,4623166044005742467,2026-06-19T18:05:39,Payment,Complete,Car clean,Aaron Eisenberg,Tyler Adams,- $200.00,,0,,0,,Mastercard *2195,,,,,Venmo,,
,4624827257965278613,2026-06-22T01:06:11,Payment,Complete,Excess,Rod Eisenberg,Aaron Eisenberg,+ $10.00,,0,,0,,,Venmo balance,,,,Venmo,,
,4625394649679197582,2026-06-22T19:53:30,Standard Transfer,Issued,,,,- $10.00,,,,,,,Cumberland Valley National Bank & Trust Company *6218,,,,Venmo,,
,4628182544206353397,2026-06-26T16:12:33,Payment,Complete,Tshirt,Maureen Rockhill,Aaron Eisenberg,+ $25.00,,0,,0,,,Venmo balance,,,,Venmo,,
,4628228449571787214,2026-06-26T17:43:45,Standard Transfer,Issued,,,,- $25.00,,,,,,,Cumberland Valley National Bank & Trust Company *6218,,,,Venmo,,
,,,,,,,,,,,,,,,,,$0.00,$0.00,,$0.00,"In case of errors or questions about your
        electronic transfers:
        Contact us as soon as you can."
CSV);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'venmo',
            'type' => 'activity',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => [],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $batch->refresh();

        $this->assertSame('completed', $batch->status);
        $this->assertSame(6, $batch->record_count);

        $activities = VenmoActivity::query()->orderBy('occurred_at')->get();

        $this->assertCount(6, $activities);
        $this->assertSame('4613052433140029613', $activities[0]->external_id);
        $this->assertSame('payment', $activities[0]->type);
        $this->assertSame('-250.00', $activities[0]->amount);
        $this->assertSame('2195', $activities[0]->funding_last_four);
        $this->assertSame('Extreme', $activities[0]->note);
        $this->assertSame('Tyler Adams', $activities[0]->to_name);

        $this->assertSame('standard_transfer', $activities[3]->type);
        $this->assertSame('-10.00', $activities[3]->amount);
        $this->assertSame('6218', $activities[3]->destination_last_four);
        $this->assertSame($activities[3]->id, $activities[2]->cashed_out_by_activity_id);

        $this->assertSame('standard_transfer', $activities[5]->type);
        $this->assertSame($activities[5]->id, $activities[4]->cashed_out_by_activity_id);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $this->assertSame(6, VenmoActivity::query()->count());
    }
}
