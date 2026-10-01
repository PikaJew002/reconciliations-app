<?php

namespace Tests\Feature\Imports;

use App\Http\Controllers\ApiTokens\ApiTokenController;
use App\Jobs\ImportTillerTransactions;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\TillerConnection;
use App\Models\User;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Imports\BankTransactionIdentity;
use App\Services\Imports\ImporterResolver;
use App\Services\Imports\TillerSheetCallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionImportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction()],
        ])->assertUnauthorized();
    }

    public function test_tokens_without_import_ability_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction()],
        ])->assertForbidden();
    }

    public function test_unlinked_sheet_rows_do_not_create_batches(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $sparse = $this->transaction([
            'description' => 'Transfer From X8955 to X1758 Daycare',
            'category' => '',
            'amount' => '$1,346.25',
            'transaction_id' => '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
            'import_tag' => '',
            'check_number' => '',
            'full_description' => 'TRANSFER FROM X8955 TO X1758 DAYCARE',
            'category_hint' => 'TRANSFER_IN: TRANSFER_IN_ACCOUNT_TRANSFER',
            'categorized_by' => '',
            'categorized_date' => '',
        ]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction(), $sparse],
        ])->assertOk()
            ->assertJson([
                'received' => 2,
            ]);

        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_numeric_amounts_and_account_numbers_are_accepted(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'amount' => -278.04,
                'account_number' => 1758,
            ])],
        ])->assertOk()
            ->assertJson([
                'received' => 1,
            ]);
    }

    public function test_blank_description_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'description' => ' ',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.description');

        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_invalid_amount_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'amount' => 'free',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.amount');
    }

    public function test_zero_amount_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'amount' => '$0.00',
            ])],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.0.amount');
    }

    public function test_duplicate_transaction_ids_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction(), $this->transaction()],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions.1.transaction_id');
    }

    public function test_empty_batch_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(), [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('transactions');
    }

    public function test_import_creates_one_batch_per_account_and_one_callback(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $checking = $this->account($user, '1758', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        $savings = $this->account($user, '6218', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        TillerConnection::query()->create([
            'user_id' => $user->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $second = $this->transaction([
            'account' => 'Joint Account 2',
            'account_number' => '6218',
            'account_id' => '6ab9a5af34a0b8d3bc2b870b',
            'transaction_id' => '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
            'description' => 'Venmo',
            'full_description' => 'VENMO',
            'amount' => '-$26.19',
            'category' => 'Transfer',
            'category_hint' => 'TRANSFER_OUT: TRANSFER_OUT_ACCOUNT_TRANSFER',
        ]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction(), $second],
        ])->assertOk()->assertJson(['received' => 2]);

        $this->assertSame('6ab9a5af34a0b8d3bc2b870a', $checking->fresh()->external_id);
        $this->assertSame('6ab9a5af34a0b8d3bc2b870b', $savings->fresh()->external_id);
        $this->assertSame(2, ImportBatch::query()->where('source', 'tiller')->count());
        $this->assertSame(2, BankTransaction::query()->count());

        $checkingBatch = ImportBatch::query()->where('metadata->account_id', $checking->id)->first();
        $this->assertNotNull($checkingBatch);
        $this->assertSame('6ab9a5af34a0b8d3bc2b870a', $checkingBatch->metadata['tiller_account_id']);
        $this->assertSame('completed', $checkingBatch->status);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $ids = json_decode($request->body(), true);
            sort($ids);

            return $request->method() === 'POST'
                && str_contains($request->url(), 'key=sheet-secret')
                && $ids === [
                    '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
                    'DZDajja0pjTbvKVbnDmOT80aoXmKpvIXRy6ZZ',
                ];
        });
    }

    public function test_unlinked_rows_are_left_out_of_batches_and_the_callback(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $this->account($user, '1758', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        Account::factory()->create([
            'user_id' => $user->id,
            'external_id' => null,
            'last_four' => '6218',
        ]);
        Account::factory()->create([
            'user_id' => $user->id,
            'external_id' => null,
            'last_four' => '6218',
        ]);
        TillerConnection::query()->create([
            'user_id' => $user->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $ambiguous = $this->transaction([
            'account_number' => '6218',
            'account_id' => '6ab9a5af34a0b8d3bc2b870b',
            'transaction_id' => '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
            'full_description' => 'VENMO',
            'amount' => '-$26.19',
        ]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction(), $ambiguous],
        ])->assertOk();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, BankTransaction::query()->count());
        Http::assertSent(function ($request): bool {
            return json_decode($request->body(), true) === ['DZDajja0pjTbvKVbnDmOT80aoXmKpvIXRy6ZZ'];
        });
    }

    public function test_csv_row_and_tiller_row_for_the_same_purchase_stay_one_transaction(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $account = $this->account($user, '1758', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        $description = 'KY FARM BUREAU KYFARM BUR';
        $fingerprint = BankTransactionIdentity::fingerprint('2026-09-28', '-278.04', $description);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'external_id' => $fingerprint,
            'posted_at' => '2026-09-28',
            'description' => $description,
            'amount' => '-278.04',
            'card_last_four' => null,
        ]);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction()],
        ])->assertOk();

        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertSame($fingerprint, BankTransaction::query()->first()?->external_id);
    }

    public function test_later_csv_import_matches_a_tiller_transaction(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $account = $this->account($user, '1758', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction()],
        ])->assertOk();

        $path = 'imports/checking.csv';
        Storage::disk('local')->put($path, implode("\n", [
            'Processed Date,Description,Credit or Debit,Amount',
            '9/28/2026,KY FARM BUREAU KYFARM BUR,Debit,278.04',
        ]));
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'metadata' => ['account_id' => $account->id],
        ]);

        $created = app(CumberlandValleyNationalBankTransactionImporter::class)->import($batch);

        $this->assertSame(0, $created);
        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertSame(
            'DZDajja0pjTbvKVbnDmOT80aoXmKpvIXRy6ZZ',
            BankTransaction::query()->first()?->external_id,
        );
    }

    public function test_capital_one_csv_matches_a_tiller_transaction_on_card_last_four(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $account = $this->account($user, '1234', CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME, Account::CREDIT_CARD);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [$this->transaction([
                'account_number' => '1234',
                'account_id' => '6ab9a5af34a0b8d3bc2b870c',
                'full_description' => 'CAPITAL ONE MOBILE PMT',
                'amount' => '-$353.70',
            ])],
        ])->assertOk();

        $path = 'imports/capital-one.csv';
        Storage::disk('local')->put($path, implode("\n", [
            'Transaction Date,Posted Date,Card No.,Description,Category,Debit,Credit',
            '2026-09-28,2026-09-28,1234,CAPITAL ONE MOBILE PMT,Payment,353.70,',
        ]));
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'metadata' => ['account_id' => $account->id],
        ]);

        $created = app(CapitalOneCreditCardTransactionImporter::class)->import($batch);

        $this->assertSame(0, $created);
        $this->assertSame(1, BankTransaction::query()->count());
    }

    public function test_reverting_one_account_batch_leaves_the_other_account(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $checking = $this->account($user, '1758', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        $savings = $this->account($user, '6218', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [
                $this->transaction(),
                $this->transaction([
                    'account_number' => '6218',
                    'account_id' => '6ab9a5af34a0b8d3bc2b870b',
                    'transaction_id' => '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
                    'full_description' => 'VENMO',
                    'amount' => '-$26.19',
                ]),
            ],
        ])->assertOk();

        $checkingBatch = ImportBatch::query()->where('metadata->account_id', $checking->id)->first();
        $this->assertNotNull($checkingBatch);

        $this->actingAs($user)
            ->get(route('accounts.imports.index', $checking))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('batches', 1)
                ->where('batches.0.id', $checkingBatch->id));

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$checking, $checkingBatch]))
            ->assertRedirect(route('accounts.imports.index', $checking));

        $this->assertSame(0, BankTransaction::query()->where('account_id', $checking->id)->count());
        $this->assertSame(1, BankTransaction::query()->where('account_id', $savings->id)->count());
        $this->assertSame('reverted', $checkingBatch->fresh()?->status);
    }

    public function test_failed_account_batch_is_omitted_from_the_callback(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();

        $user = User::factory()->create();
        $this->account($user, '1758', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        $this->account($user, '6218', CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME);
        TillerConnection::query()->create([
            'user_id' => $user->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);
        Sanctum::actingAs($user, [ApiTokenController::ABILITY_TRANSACTION_IMPORT]);

        $this->postJson(route('api.transactions.import'), [
            'transactions' => [
                $this->transaction(),
                $this->transaction([
                    'account_number' => '6218',
                    'account_id' => '6ab9a5af34a0b8d3bc2b870b',
                    'transaction_id' => '7yEPLLP6XLhKQebKB6LrsjN94Ed79LF4rAmNE',
                    'full_description' => 'VENMO',
                    'amount' => '-$26.19',
                ]),
            ],
        ])->assertOk();

        $batches = ImportBatch::query()->orderBy('id')->get();
        $this->assertCount(2, $batches);
        Storage::disk('local')->delete($batches[0]->storage_path);
        $syncId = $batches[0]->metadata['tiller_sync_id'];

        (new ImportTillerTransactions($user->id, $syncId))
            ->handle(app(ImporterResolver::class), app(TillerSheetCallback::class));

        Http::assertSent(function ($request) use ($batches): bool {
            $ids = json_decode($request->body(), true);

            return $ids === $batches[1]->fresh()->metadata['acknowledged_transaction_ids'];
        });
        $this->assertSame('failed', $batches[0]->fresh()?->status);
        $this->assertSame('completed', $batches[1]->fresh()?->status);
    }

    private function account(User $user, string $lastFour, string $institution, string $type = Account::CHECKING): Account
    {
        return Account::factory()->create([
            'user_id' => $user->id,
            'external_id' => null,
            'last_four' => $lastFour,
            'institution_name' => $institution,
            'account_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function transaction(array $overrides = []): array
    {
        return [
            'date' => '9/28/2026',
            'description' => 'KY Farm Bureau Kyfarm Bur',
            'category' => 'Insurance',
            'amount' => '-$278.04',
            'account' => 'Joint Account 1',
            'account_number' => '1758',
            'institution' => 'Cumberland Valley National Bank & Trust Company',
            'month' => '9/1/26',
            'week' => '9/27/26',
            'transaction_id' => 'DZDajja0pjTbvKVbnDmOT80aoXmKpvIXRy6ZZ',
            'account_id' => '6ab9a5af34a0b8d3bc2b870a',
            'import_tag' => null,
            'check_number' => null,
            'full_description' => 'KY FARM BUREAU KYFARM BUR',
            'date_added' => '9/29/26',
            'category_hint' => 'GENERAL_SERVICES: GENERAL_SERVICES_INSURANCE',
            'categorized_by' => 'Description Match',
            'categorized_date' => '9/29/26',
            'source' => 'Plaid',
            ...$overrides,
        ];
    }
}
