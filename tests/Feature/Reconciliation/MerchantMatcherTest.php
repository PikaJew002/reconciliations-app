<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Reconciliation\MerchantMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_walmart_descriptions_to_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'description' => 'POS DEB 1716 04/29/26 40269900 WAL-MART #1190 120 JILL DR BEREA KY C#2195',
            'normalized_description' => 'pos deb 1716 04/29/26 40269900 wal-mart #1190 120 jill dr berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertFalse($matcher->matchTransaction($transaction->fresh(), $user->id));
    }

    public function test_leaves_unrelated_transactions_unmatched(): void
    {
        $user = User::factory()->create();
        Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'description' => 'VENMO PURCHASE 1051937135825',
            'normalized_description' => 'venmo purchase 1051937135825',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
    }
}
