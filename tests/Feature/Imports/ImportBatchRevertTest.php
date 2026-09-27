<?php

namespace Tests\Feature\Imports;

use App\Jobs\ProcessImportBatch;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\OrderItem;
use App\Models\PendingSpend;
use App\Models\PlannedOccurrence;
use App\Models\PlannedTemplate;
use App\Models\TransactionAllocation;
use App\Models\User;
use App\Models\VenmoActivity;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use App\Services\Imports\ImporterResolver;
use App\Services\Reconciliation\TransferPairingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImportBatchRevertTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_revert_a_bank_import_and_reimport_the_same_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);

        $csv = $this->bankCsv();
        $path = 'imports/revert-bank.csv';
        Storage::disk('local')->put($path, $csv);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'chase.csv',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $this->assertSame(2, BankTransaction::query()->where('import_batch_id', $batch->id)->count());
        Storage::disk('local')->assertExists($path);

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$account, $batch]))
            ->assertRedirect(route('accounts.imports.index', $account))
            ->assertSessionHas('success', 'Import "chase.csv" reverted.');

        $batch->refresh();

        $this->assertSame('reverted', $batch->status);
        $this->assertSame(0, BankTransaction::query()->where('account_id', $account->id)->count());
        Storage::disk('local')->assertMissing($path);

        $reimportPath = 'imports/reimport-bank.csv';
        Storage::disk('local')->put($reimportPath, $csv);

        $reimport = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'chase.csv',
            'storage_path' => $reimportPath,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        (new ProcessImportBatch($reimport))->handle(app(ImporterResolver::class));

        $reimport->refresh();

        $this->assertSame('completed', $reimport->status);
        $this->assertSame(2, $reimport->record_count);
        $this->assertSame(2, BankTransaction::query()->where('import_batch_id', $reimport->id)->count());
    }

    public function test_reverted_bank_file_can_be_imported_onto_another_account(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $accountA = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);
        $accountB = Account::factory()->create([
            'user_id' => $user->id,
            'institution_name' => CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            'account_type' => Account::CHECKING,
            'is_active' => true,
        ]);

        $path = 'imports/wrong-account.csv';
        Storage::disk('local')->put($path, $this->bankCsv());

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $path,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => (string) $accountA->id],
        ]);

        (new ProcessImportBatch($batch))->handle(app(ImporterResolver::class));

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$accountA, $batch]))
            ->assertRedirect(route('accounts.imports.index', $accountA));

        $correctPath = 'imports/correct-account.csv';
        Storage::disk('local')->put($correctPath, $this->bankCsv());

        $correctBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'storage_path' => $correctPath,
            'status' => 'pending',
            'record_count' => 0,
            'metadata' => ['account_id' => (string) $accountB->id],
        ]);

        (new ProcessImportBatch($correctBatch))->handle(app(ImporterResolver::class));

        $this->assertSame(0, BankTransaction::query()->where('account_id', $accountA->id)->count());
        $this->assertSame(2, BankTransaction::query()->where('account_id', $accountB->id)->count());
    }

    public function test_reverting_a_bank_import_unpairs_the_transfer_partner(): void
    {
        $user = User::factory()->create();
        $accountA = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
            'is_active' => true,
        ]);
        $accountB = Account::factory()->create([
            'user_id' => $user->id,
            'account_type' => Account::CHECKING,
            'last_four' => '1758',
            'is_active' => true,
        ]);

        $batchA = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'debit.csv',
            'metadata' => ['account_id' => (string) $accountA->id],
        ]);
        $batchB = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'credit.csv',
            'metadata' => ['account_id' => (string) $accountB->id],
        ]);

        $debit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batchA->id,
            'account_id' => $accountA->id,
            'amount' => -250.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 LEFTOVER',
            'normalized_description' => 'transfer from x6218 to x1758 leftover',
            'status' => 'unmatched',
        ]);
        $credit = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batchB->id,
            'account_id' => $accountB->id,
            'amount' => 250.00,
            'posted_at' => '2026-08-01',
            'description' => 'TRANSFER FROM X6218 TO X1758 LEFTOVER',
            'normalized_description' => 'transfer from x6218 to x1758 leftover',
            'status' => 'unmatched',
        ]);

        app(TransferPairingService::class)->pairForUser($user->id);

        $this->assertSame(BankTransaction::CLASSIFICATION_TRANSFER, $credit->fresh()->classification);
        $this->assertSame('ignored', $credit->fresh()->status);

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$accountA, $batchA]))
            ->assertRedirect(route('accounts.imports.index', $accountA));

        $this->assertDatabaseMissing('bank_transactions', ['id' => $debit->id]);
        $this->assertDatabaseMissing('transaction_transfer_links', [
            'debit_transaction_id' => $debit->id,
            'credit_transaction_id' => $credit->id,
        ]);

        $credit->refresh();
        $this->assertNull($credit->classification);
        $this->assertNull($credit->transfer_group_id);
        $this->assertSame('unmatched', $credit->status);
    }

    public function test_reverting_a_bank_import_reopens_allocated_orders(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'spend.csv',
            'metadata' => ['account_id' => (string) $account->id],
        ]);
        $orderBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -7.39,
            'status' => 'matched',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $orderBatch->id,
            'merchant_id' => $merchant->id,
            'order_number' => 'WM-REVERT',
            'total' => 7.39,
            'status' => 'reconciled',
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Snack',
            'quantity' => 1,
            'unit_price' => 7.39,
            'extended_price' => 7.39,
        ]);

        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'description' => 'Snack',
            'amount' => 7.39,
            'category_id' => null,
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 7.39,
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$account, $batch]))
            ->assertRedirect(route('accounts.imports.index', $account));

        $this->assertDatabaseMissing('bank_transactions', ['id' => $transaction->id]);
        $this->assertDatabaseMissing('transaction_allocations', ['order_component_id' => $component->id]);
        $this->assertSame('imported', $order->fresh()->status);
    }

    public function test_reverting_a_bank_import_resets_planned_occurrences(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'paycheck.csv',
            'metadata' => ['account_id' => (string) $account->id],
        ]);
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'amount' => 3000.00,
            'status' => 'ignored',
        ]);
        $template = PlannedTemplate::factory()->create([
            'user_id' => $user->id,
        ]);
        $occurrence = PlannedOccurrence::factory()
            ->forTemplate($template, '2026-03-01')
            ->resolved($transaction)
            ->create();

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$account, $batch]))
            ->assertRedirect(route('accounts.imports.index', $account));

        $occurrence->refresh();

        $this->assertNull($occurrence->bank_transaction_id);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $occurrence->status);
    }

    public function test_owner_can_revert_a_venmo_import(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = 'imports/venmo.csv';
        Storage::disk('local')->put($path, 'id,datetime');

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'venmo',
            'type' => 'activity',
            'original_filename' => 'venmo.csv',
            'storage_path' => $path,
        ]);
        $activity = VenmoActivity::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'match_status' => VenmoActivity::STATUS_CONFIRMED,
        ]);
        $pending = PendingSpend::factory()->venmo()->resolved()->create([
            'user_id' => $user->id,
            'venmo_activity_id' => $activity->id,
        ]);

        $this->actingAs($user)
            ->delete(route('venmo.imports.destroy', $batch))
            ->assertRedirect(route('venmo.imports.index'))
            ->assertSessionHas('success', 'Import "venmo.csv" reverted.');

        $this->assertSame('reverted', $batch->fresh()->status);
        $this->assertDatabaseMissing('venmo_activities', ['id' => $activity->id]);
        $this->assertNull($pending->fresh()->venmo_activity_id);
        $this->assertSame(PendingSpend::STATUS_PENDING, $pending->fresh()->status);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_owner_can_revert_an_order_import_and_unmatch_bank_transactions(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $bankBatch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'metadata' => ['account_id' => (string) $account->id],
        ]);
        $path = 'imports/walmart.json';
        Storage::disk('local')->put($path, '[]');

        $batch = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'walmart',
            'type' => 'orders',
            'original_filename' => 'walmart.json',
            'storage_path' => $path,
        ]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $bankBatch->id,
            'account_id' => $account->id,
            'merchant_id' => $merchant->id,
            'amount' => -12.00,
            'status' => 'matched',
        ]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'merchant_id' => $merchant->id,
            'order_number' => 'WM-DELETE',
            'total' => 12.00,
            'status' => 'reconciled',
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Milk',
            'quantity' => 1,
            'unit_price' => 12.00,
            'extended_price' => 12.00,
        ]);

        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'type' => 'product',
            'description' => 'Milk',
            'amount' => 12.00,
            'category_id' => null,
        ]);

        TransactionAllocation::factory()->create([
            'bank_transaction_id' => $transaction->id,
            'order_component_id' => $component->id,
            'allocated_amount' => 12.00,
        ]);

        $this->actingAs($user)
            ->delete(route('orders.imports.destroy', ['walmart', $batch]))
            ->assertRedirect(route('orders.imports.index', 'walmart'))
            ->assertSessionHas('success', 'Import "walmart.json" reverted.');

        $this->assertSame('reverted', $batch->fresh()->status);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertSame('unmatched', $transaction->fresh()->status);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_guests_are_redirected_from_revert(): void
    {
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create([
            'source' => 'bank',
            'type' => 'transactions',
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        $this->delete(route('accounts.imports.destroy', [$account, $batch]))
            ->assertRedirect('/login');
    }

    public function test_user_cannot_revert_another_users_import(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $owner->id]);
        $batch = ImportBatch::factory()->create([
            'user_id' => $owner->id,
            'source' => 'bank',
            'type' => 'transactions',
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        $this->actingAs($other)
            ->delete(route('accounts.imports.destroy', [$account, $batch]))
            ->assertForbidden();

        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_in_progress_and_already_reverted_imports_cannot_be_reverted(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        $processing = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'processing',
            'metadata' => ['account_id' => (string) $account->id],
        ]);
        $reverted = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'reverted',
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$account, $processing]))
            ->assertRedirect(route('accounts.imports.index', $account))
            ->assertSessionHas('error', 'This import cannot be reverted.');

        $this->actingAs($user)
            ->delete(route('accounts.imports.destroy', [$account, $reverted]))
            ->assertRedirect(route('accounts.imports.index', $account))
            ->assertSessionHas('error', 'This import cannot be reverted.');

        $this->assertSame('processing', $processing->fresh()->status);
        $this->assertSame('reverted', $reverted->fresh()->status);
    }

    public function test_account_import_index_and_show_include_revert_fields(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $completed = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'original_filename' => 'done.csv',
            'created_at' => now()->subMinute(),
            'metadata' => ['account_id' => (string) $account->id],
        ]);
        $processing = ImportBatch::factory()->create([
            'user_id' => $user->id,
            'source' => 'bank',
            'type' => 'transactions',
            'status' => 'processing',
            'original_filename' => 'busy.csv',
            'created_at' => now(),
            'metadata' => ['account_id' => (string) $account->id],
        ]);

        $this->actingAs($user)
            ->get(route('accounts.imports.index', $account))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Imports')
                ->has('batches', 2)
                ->where('batches.0.can_revert', false)
                ->where('batches.1.can_revert', true));

        $this->actingAs($user)
            ->get(route('accounts.imports.show', [$account, $completed]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('can_revert', true)
                ->where('revert_url', route('accounts.imports.destroy', [$account, $completed])));

        $this->actingAs($user)
            ->get(route('accounts.imports.show', [$account, $processing]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Imports/Show')
                ->where('can_revert', false)
                ->where('revert_url', null));
    }

    protected function bankCsv(): string
    {
        return <<<'CSV'
Account Name,Processed Date,Description,Check Number,Credit or Debit,Amount
Joint Account 2,4/30/26,TRANSFER FROM X1758 TO X6218  LEFTOVER 4-30-26,,Credit,213.11
Joint Account 2,4/30/26,POS DEB 1716 04/29/26 40269900 WAL-MART #1190 120 JILL DR BEREA         KY C#2195,,Debit,6.75
CSV;
    }
}
