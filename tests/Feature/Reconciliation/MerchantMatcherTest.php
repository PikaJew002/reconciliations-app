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
            'supports_order_import' => true,
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -71.98,
            'description' => 'POS DEB 1716 04/29/26 40269900 WAL-MART #1190 120 JILL DR BEREA KY C#2195',
            'normalized_description' => 'pos deb 1716 04/29/26 40269900 wal-mart #1190 120 jill dr berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertFalse($matcher->matchTransaction($transaction->fresh(), $user->id));
    }

    public function test_does_not_create_walmart_merchant_when_missing(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -20.00,
            'description' => 'POS DEB 1716 04/29/26 40269900 WAL-MART #1190 BEREA KY C#2195',
            'normalized_description' => 'pos deb 1716 04/29/26 40269900 wal-mart #1190 berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_skips_amazon_card_transactions(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -42.10,
            'description' => 'DBT CRD 0848 07/22/26 DJJKQM32 AMAZON MKTPL*XE7F71XN3 SEATTLE WA C#2195',
            'normalized_description' => 'dbt crd 0848 07/22/26 djjkqm32 amazon mktpl*xe7f71xn3 seattle wa c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($transaction, $user->id));
        $this->assertNull($transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 0);
    }

    public function test_skips_venmo_and_transfer_noise(): void
    {
        $user = User::factory()->create();
        Merchant::factory()->create([
            'user_id' => $user->id,
            'normalized_name' => 'walmart',
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $venmo = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -14.15,
            'description' => 'VENMO PURCHASE 1051937135825',
            'normalized_description' => 'venmo purchase 1051937135825',
            'status' => 'unmatched',
        ]);

        $transfer = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -50.00,
            'description' => 'TRANSFER FROM X6218 TO X1758',
            'normalized_description' => 'transfer from x6218 to x1758',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertFalse($matcher->matchTransaction($venmo, $user->id));
        $this->assertFalse($matcher->matchTransaction($transfer, $user->id));
        $this->assertNull($venmo->fresh()->merchant_id);
        $this->assertNull($transfer->fresh()->merchant_id);
    }

    public function test_creates_merchant_for_card_pos_spend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -12.25,
            'description' => 'DBT CRD 1232 07/22/26 DJSXXUSB BUC-EE S #0055 RICHMOND KY C#2525',
            'normalized_description' => 'dbt crd 1232 07/22/26 djsxxusb buc-ee s #0055 richmond ky c#2525',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));

        $merchant = Merchant::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($merchant);
        $this->assertSame('buc ee', $merchant->normalized_name);
        $this->assertFalse($merchant->supports_order_import);
        $this->assertSame(Merchant::OTHER, $merchant->type);
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
    }

    public function test_fuzzy_matches_similar_card_pos_descriptions_to_existing_merchant(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Circlek',
            'normalized_name' => 'circlek',
            'supports_order_import' => false,
            'type' => Merchant::OTHER,
        ]);
        $account = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'import_batch_id' => $batch->id,
            'account_id' => $account->id,
            'merchant_id' => null,
            'amount' => -30.42,
            'description' => 'POS DEB 1343 07/24/26 13437780 CIRCLEK #4703255 300 R 300 RICHMOND ROAD BEREA KY C#2195',
            'normalized_description' => 'pos deb 1343 07/24/26 13437780 circlek #4703255 300 r 300 richmond road berea ky c#2195',
            'status' => 'unmatched',
        ]);

        $matcher = app(MerchantMatcher::class);

        $this->assertTrue($matcher->matchTransaction($transaction, $user->id));
        $this->assertSame($merchant->id, $transaction->fresh()->merchant_id);
        $this->assertDatabaseCount('merchants', 1);
    }
}
