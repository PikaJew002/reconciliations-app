<?php

namespace Tests\Feature\Accounts;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\Accounts\OffBookAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OffBookAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_creates_one_off_book_account_lazily(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseMissing('accounts', [
            'user_id' => $user->id,
            'external_id' => Account::OFF_BOOK_EXTERNAL_ID,
        ]);

        $first = app(OffBookAccountService::class)->ensureForUser($user->id);
        $second = app(OffBookAccountService::class)->ensureForUser($user->id);

        $this->assertTrue($first->isOffBook());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(Account::OFF_BOOK_NAME, $first->name);
        $this->assertSame(Account::OFF_BOOK, $first->account_type);
        $this->assertSame(1, Account::query()->where('user_id', $user->id)->offBook()->count());
        $this->assertSame(1, Account::query()->where('user_id', $user->id)->count());
    }

    public function test_ensure_restores_a_soft_deleted_off_book_account(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->offBook()->create();
        $account->delete();

        $this->assertTrue($account->fresh()->trashed());

        $ensured = app(OffBookAccountService::class)->ensureForUser($user->id);

        $this->assertSame($account->id, $ensured->id);
        $this->assertFalse($ensured->trashed());
        $this->assertTrue($ensured->is_active);
    }

    public function test_ensure_moves_existing_non_bank_tenders_onto_off_book(): void
    {
        $user = User::factory()->create();
        $real = Account::factory()->for($user)->create(['is_active' => true]);
        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $real->id,
            'amount' => -50.00,
            'description' => 'Ending in 8723',
            'metadata' => [
                'source' => 'non_bank_tender',
                'kind' => 'gift_card',
            ],
        ]);

        $offBook = app(OffBookAccountService::class)->ensureForUser($user->id);

        $this->assertSame($offBook->id, $transaction->fresh()->account_id);
        $this->assertNotSame($real->id, $transaction->fresh()->account_id);
    }

    public function test_ensure_does_not_create_off_book_for_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        app(OffBookAccountService::class)->ensureForUser($user->id);

        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'external_id' => Account::OFF_BOOK_EXTERNAL_ID,
        ]);
        $this->assertDatabaseMissing('accounts', [
            'user_id' => $other->id,
            'external_id' => Account::OFF_BOOK_EXTERNAL_ID,
        ]);
    }
}
