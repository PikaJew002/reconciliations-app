<?php

namespace Tests\Feature\Plaid;

use App\Jobs\SyncPlaidTransactions;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\PlaidAccount;
use App\Models\PlaidItem;
use App\Models\User;
use App\Services\Plaid\PlaidTransactionSync;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlaidIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.plaid.client_id' => 'test-client-id',
            'services.plaid.secret' => 'test-secret',
            'services.plaid.env' => 'sandbox',
            'services.plaid.webhook_url' => null,
            'services.plaid.redirect_uri' => 'https://reconciliations-app.test/plaid/oauth',
        ]);
    }

    public function test_plaid_enabled_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->fill(['plaid_enabled' => true]);
        $user->save();

        $this->assertFalse($user->fresh()->plaid_enabled);
    }

    public function test_flag_off_cannot_create_a_link_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('plaid.link-token'), [
                'import_history' => false,
            ])
            ->assertForbidden();
    }

    public function test_accounts_page_hides_plaid_until_the_flag_is_on(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('plaidEnabled', false)
                ->has('plaidItems', 0));

        $enabled = User::factory()->plaidEnabled()->create();

        $this->actingAs($enabled)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('plaidEnabled', true));
    }

    public function test_declining_history_requests_one_day_and_a_long_window_is_clamped(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/*' => Http::response([
                'link_token' => 'link-sandbox-test',
                'request_id' => 'req-link',
            ]),
        ]);

        $user = User::factory()->plaidEnabled()->create();

        $this->actingAs($user)
            ->postJson(route('plaid.link-token'), [
                'import_history' => false,
            ])
            ->assertOk()
            ->assertJsonPath('mode', 'create')
            ->assertJsonPath('link_token', 'link-sandbox-test');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://sandbox.plaid.com/link/token/create'
                && $request['products'] === ['transactions']
                && $request['transactions']['days_requested'] === 1
                && $request['country_codes'] === ['US']
                && $request['redirect_uri'] === 'https://reconciliations-app.test/plaid/oauth'
                && ! array_key_exists('webhook', $request->data());
        });

        $this->assertSame(1, session('plaid.link.days_requested'));
        $this->assertSame(now()->toDateString(), session('plaid.link.import_from'));

        $this->actingAs($user)
            ->postJson(route('plaid.link-token'), [
                'import_history' => true,
                'import_from' => now()->subDays(800)->toDateString(),
            ])
            ->assertOk();

        $this->assertSame(730, session('plaid.link.days_requested'));
        $this->assertSame(now()->subDays(730)->toDateString(), session('plaid.link.import_from'));
    }

    public function test_production_raises_short_history_requests_to_thirty_days(): void
    {
        config(['services.plaid.env' => 'production']);

        Http::fake([
            'https://production.plaid.com/*' => Http::response([
                'link_token' => 'link-production-test',
                'request_id' => 'req-link',
            ]),
        ]);

        $user = User::factory()->plaidEnabled()->create();
        $start = now()->subDays(10)->toDateString();

        $this->actingAs($user)
            ->postJson(route('plaid.link-token'), [
                'import_history' => true,
                'import_from' => $start,
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://production.plaid.com/link/token/create'
            && $request['transactions']['days_requested'] === 30);

        $this->assertSame(30, session('plaid.link.days_requested'));
        $this->assertSame($start, session('plaid.link.import_from'));
    }

    public function test_exchange_stores_an_encrypted_token_and_does_not_return_it(): void
    {
        Http::fake(function (Request $request) {
            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/item/public_token/exchange' => Http::response([
                    'access_token' => 'access-sandbox-secret',
                    'item_id' => 'item-sandbox-1',
                    'request_id' => 'req-exchange',
                ]),
                '/institutions/get_by_id' => Http::response([
                    'institution' => [
                        'name' => 'First Platypus Bank',
                        'institution_id' => 'ins_109508',
                    ],
                    'request_id' => 'req-institution',
                ]),
                '/accounts/get' => Http::response([
                    'accounts' => [[
                        'account_id' => 'acct-checking',
                        'persistent_account_id' => 'persistent-1',
                        'name' => 'Plaid Checking',
                        'mask' => '0000',
                        'type' => 'depository',
                        'subtype' => 'checking',
                    ]],
                    'request_id' => 'req-accounts',
                ]),
                default => Http::response(['error_code' => 'UNEXPECTED'], 500),
            };
        });

        $user = User::factory()->plaidEnabled()->create();

        $response = $this->actingAs($user)
            ->withSession([
                'plaid.link' => [
                    'mode' => 'create',
                    'link_token' => 'link-sandbox-test',
                    'days_requested' => 1,
                    'import_from' => now()->toDateString(),
                    'item_id' => null,
                ],
            ])
            ->post(route('plaid.items.store'), [
                'public_token' => 'public-sandbox-test',
                'institution_id' => 'ins_109508',
                'link_session_id' => 'session-1',
            ]);

        $item = PlaidItem::query()->first();

        $response->assertRedirect(route('plaid.items.accounts', $item));
        $this->assertStringNotContainsString('access-sandbox-secret', $response->getContent());
        $this->assertNotSame('access-sandbox-secret', $item->getRawOriginal('access_token'));
        $this->assertSame('access-sandbox-secret', $item->access_token);
        $this->assertSame('First Platypus Bank', $item->institution_name);
        $this->assertSame('session-1', $item->link_session_id);
        $this->assertDatabaseHas('plaid_accounts', [
            'plaid_item_id' => $item->id,
            'plaid_account_id' => 'acct-checking',
            'mask' => '0000',
        ]);
        $this->assertNull(session('plaid.link'));
    }

    public function test_duplicate_institution_is_rejected_before_exchange(): void
    {
        Http::fake();

        $user = User::factory()->plaidEnabled()->create();
        PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-existing',
            'access_token' => 'access-existing',
            'institution_id' => 'ins_109508',
            'days_requested' => 1,
            'import_from' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->withSession([
                'plaid.link' => [
                    'mode' => 'create',
                    'link_token' => 'link-sandbox-test',
                    'days_requested' => 1,
                    'import_from' => now()->toDateString(),
                    'item_id' => null,
                ],
            ])
            ->from(route('accounts.index'))
            ->post(route('plaid.items.store'), [
                'public_token' => 'public-sandbox-test',
                'institution_id' => 'ins_109508',
                'link_session_id' => 'session-2',
            ])
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHasErrors('institution_id');

        Http::assertNothingSent();
        $this->assertSame(1, PlaidItem::query()->count());
    }

    public function test_mapping_suggests_last_four_and_never_creates_accounts(): void
    {
        Queue::fake();

        $user = User::factory()->plaidEnabled()->create();
        $checking = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Checking',
            'last_four' => '0000',
        ]);
        Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'Other',
            'last_four' => '9999',
        ]);
        Account::factory()->for($user)->offBook()->create();

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-sandbox-1',
            'access_token' => 'access-sandbox-secret',
            'institution_id' => 'ins_109508',
            'institution_name' => 'First Platypus Bank',
            'days_requested' => 1,
            'import_from' => now()->toDateString(),
        ]);
        $plaidAccount = PlaidAccount::query()->create([
            'plaid_item_id' => $item->id,
            'plaid_account_id' => 'acct-checking',
            'name' => 'Plaid Checking',
            'mask' => '0000',
            'type' => 'depository',
            'subtype' => 'checking',
        ]);

        $this->actingAs($user)
            ->get(route('plaid.items.accounts', $item))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Plaid/Map')
                ->where('plaidAccounts.0.suggested_account_id', $checking->id)
                ->has('spendableAccounts', 2));

        $before = Account::query()->count();

        $this->actingAs($user)
            ->put(route('plaid.items.accounts.update', $item), [
                'mappings' => [[
                    'id' => $plaidAccount->id,
                    'account_id' => $checking->id,
                ]],
            ])
            ->assertRedirect(route('accounts.index'));

        $this->assertSame($checking->id, $plaidAccount->fresh()->account_id);
        $this->assertSame($before, Account::query()->count());
        Queue::assertPushed(SyncPlaidTransactions::class);
    }

    public function test_sync_flips_amounts_drops_old_rows_and_stitches_pending_posts(): void
    {
        Bus::fake();

        $user = User::factory()->plaidEnabled()->create();
        $checking = Account::factory()->create([
            'user_id' => $user->id,
            'last_four' => '0000',
        ]);
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-sandbox-1',
            'access_token' => 'access-sandbox-secret',
            'institution_id' => 'ins_109508',
            'days_requested' => 1,
            'import_from' => now()->toDateString(),
        ]);
        PlaidAccount::query()->create([
            'plaid_item_id' => $item->id,
            'plaid_account_id' => 'acct-checking',
            'name' => 'Plaid Checking',
            'mask' => '0000',
            'type' => 'depository',
            'account_id' => $checking->id,
        ]);
        PlaidAccount::query()->create([
            'plaid_item_id' => $item->id,
            'plaid_account_id' => 'acct-unmapped',
            'name' => 'Plaid Saving',
            'mask' => '1111',
            'type' => 'depository',
        ]);

        $kept = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $checking->id,
            'external_id' => 'keep-me',
            'status' => 'matched',
            'posted_at' => now()->toDateString(),
            'amount' => -4,
        ]);

        $pages = [
            '' => [
                'added' => [
                    $this->plaidTransaction('txn-coffee', 'acct-checking', 12.34, now()->toDateString(), 'COFFEE SHOP'),
                    $this->plaidTransaction('txn-old', 'acct-checking', 9, now()->subDays(3)->toDateString(), 'OLD'),
                    $this->plaidTransaction('txn-unmapped', 'acct-unmapped', 8, now()->toDateString(), 'SAVINGS'),
                    $this->plaidTransaction('pending-1', 'acct-checking', 20, now()->toDateString(), 'PENDING STORE', true),
                ],
                'modified' => [],
                'removed' => [],
                'next_cursor' => 'cursor-1',
                'has_more' => true,
                'transactions_update_status' => 'INITIAL_UPDATE_COMPLETE',
            ],
            'cursor-1' => [
                'added' => [
                    $this->plaidTransaction('txn-page-2', 'acct-checking', 3, now()->toDateString(), 'PAGE TWO'),
                ],
                'modified' => [],
                'removed' => [],
                'next_cursor' => 'cursor-2',
                'has_more' => false,
                'transactions_update_status' => 'HISTORICAL_UPDATE_COMPLETE',
            ],
        ];

        $phase = 'first';

        Http::fake(function (Request $request) use (&$pages, &$phase) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/accounts/get') {
                return Http::response([
                    'accounts' => [[
                        'account_id' => 'acct-checking',
                        'name' => 'Plaid Checking',
                        'mask' => '0000',
                        'type' => 'depository',
                        'subtype' => 'checking',
                    ]],
                    'request_id' => 'req-accounts',
                ]);
            }

            if ($phase === 'second') {
                return Http::response([
                    'added' => [
                        $this->plaidTransaction('posted-1', 'acct-checking', 21.5, now()->toDateString(), 'STORE POSTED', false, 'pending-1'),
                    ],
                    'modified' => [],
                    'removed' => [
                        ['transaction_id' => 'pending-1'],
                        ['transaction_id' => 'keep-me'],
                    ],
                    'next_cursor' => 'cursor-3',
                    'has_more' => false,
                    'transactions_update_status' => 'HISTORICAL_UPDATE_COMPLETE',
                    'request_id' => 'req-sync-2',
                ]);
            }

            $cursor = $request->data()['cursor'] ?? '';
            $payload = $pages[$cursor] ?? null;

            if (! is_array($payload)) {
                throw new \RuntimeException('Unexpected cursor '.$cursor);
            }

            return Http::response($payload + ['request_id' => 'req-sync']);
        });

        $created = app(PlaidTransactionSync::class)->sync($item->fresh());

        $this->assertSame(3, $created);
        $this->assertSame('cursor-2', $item->fresh()->cursor);

        $coffee = BankTransaction::query()->where('external_id', 'txn-coffee')->first();
        $this->assertNotNull($coffee);
        $this->assertSame('-12.34', $coffee->amount);
        $this->assertSame('COFFEE SHOP', $coffee->description);
        $this->assertSame('FOOD_AND_DRINK', $coffee->metadata['plaid']['personal_finance_category']['primary']);
        $this->assertNull($coffee->classification);

        $this->assertDatabaseMissing('bank_transactions', ['external_id' => 'txn-old']);
        $this->assertDatabaseMissing('bank_transactions', ['external_id' => 'txn-unmapped']);
        $this->assertDatabaseHas('bank_transactions', ['external_id' => 'pending-1']);
        $this->assertDatabaseHas('bank_transactions', ['external_id' => 'txn-page-2']);

        $phase = 'second';

        app(PlaidTransactionSync::class)->sync($item->fresh());

        $this->assertDatabaseMissing('bank_transactions', ['external_id' => 'pending-1']);
        $posted = BankTransaction::query()->where('external_id', 'posted-1')->first();
        $this->assertNotNull($posted);
        $this->assertSame('-21.50', $posted->amount);
        $this->assertSame('STORE POSTED', $posted->description);
        $this->assertNotNull($kept->fresh());
        $this->assertSame(1, BankTransaction::query()->where('external_id', 'txn-coffee')->count());

        $this->actingAs($user)
            ->get(route('accounts.imports.index', $checking))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Accounts/Imports')
                ->where('batches.0.source', 'plaid'));
    }

    public function test_update_mode_link_token_does_not_return_the_access_token(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/*' => Http::response([
                'link_token' => 'link-sandbox-update',
                'request_id' => 'req-update',
            ]),
        ]);

        $user = User::factory()->plaidEnabled()->create();
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-sandbox-1',
            'access_token' => 'access-sandbox-secret',
            'institution_id' => 'ins_109508',
            'days_requested' => 1,
            'import_from' => now()->toDateString(),
            'error_code' => 'ITEM_LOGIN_REQUIRED',
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('plaid.items.link-token', $item))
            ->assertOk()
            ->assertJsonPath('mode', 'update');

        $this->assertStringNotContainsString('access-sandbox-secret', $response->getContent());

        Http::assertSent(fn (Request $request): bool => $request['products'] === []
            && $request['access_token'] === 'access-sandbox-secret'
            && $request['redirect_uri'] === 'https://reconciliations-app.test/plaid/oauth'
            && ! array_key_exists('transactions', $request->data()));
    }

    public function test_webhook_queues_sync_and_rejects_a_bad_signature(): void
    {
        Queue::fake();

        $user = User::factory()->plaidEnabled()->create();
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-sandbox-1',
            'access_token' => 'access-sandbox-secret',
            'institution_id' => 'ins_109508',
            'days_requested' => 1,
            'import_from' => now()->toDateString(),
        ]);

        $privateKey = <<<'PEM'
-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIFDWzOFOPp1eaZ7nQRGjY4Te6XIRjoLKtkCvdqUm/r3VoAoGCCqGSM49
AwEHoUQDQgAE8MdeyAgraiYUF7Upy1s8zYdo0yb+R+zreDAuzmZd1rQ527JFvFxS
5GNyls4rgcarBYHHgWixcA0z10Ayt4eFhg==
-----END EC PRIVATE KEY-----
PEM;
        $key = openssl_pkey_get_private($privateKey);
        $details = openssl_pkey_get_details($key);

        Http::fake([
            'https://sandbox.plaid.com/webhook_verification_key/get' => Http::response([
                'key' => [
                    'alg' => 'ES256',
                    'crv' => 'P-256',
                    'kid' => 'test-kid',
                    'kty' => 'EC',
                    'use' => 'sig',
                    'x' => JWT::urlsafeB64Encode($details['ec']['x']),
                    'y' => JWT::urlsafeB64Encode($details['ec']['y']),
                    'expired_at' => null,
                ],
                'request_id' => 'req-key',
            ]),
        ]);

        $body = json_encode([
            'webhook_type' => 'TRANSACTIONS',
            'webhook_code' => 'SYNC_UPDATES_AVAILABLE',
            'item_id' => $item->item_id,
            'initial_update_complete' => true,
            'historical_update_complete' => false,
        ]);
        $jwt = JWT::encode([
            'iat' => time(),
            'request_body_sha256' => hash('sha256', $body),
        ], $privateKey, 'ES256', 'test-kid');

        $this->call('POST', route('plaid.webhook'), [], [], [], [
            'HTTP_PLAID_VERIFICATION' => $jwt,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        Queue::assertPushed(SyncPlaidTransactions::class, fn (SyncPlaidTransactions $job): bool => $job->plaidItemId === $item->id);

        $this->call('POST', route('plaid.webhook'), [], [], [], [
            'HTTP_PLAID_VERIFICATION' => $jwt.'tampered',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertUnauthorized();
    }

    public function test_disconnect_removes_the_item_at_plaid(): void
    {
        Http::fake([
            'https://sandbox.plaid.com/item/remove' => Http::response([
                'request_id' => 'req-remove',
            ]),
        ]);

        $user = User::factory()->plaidEnabled()->create();
        $checking = Account::factory()->create(['user_id' => $user->id]);
        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => 'item-sandbox-1',
            'access_token' => 'access-sandbox-secret',
            'institution_id' => 'ins_109508',
            'days_requested' => 1,
            'import_from' => now()->toDateString(),
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $checking->id,
            'external_id' => 'txn-keep',
        ]);

        $this->actingAs($user)
            ->delete(route('plaid.items.destroy', $item))
            ->assertRedirect(route('accounts.index'));

        $this->assertDatabaseMissing('plaid_items', ['id' => $item->id]);
        $this->assertDatabaseHas('bank_transactions', ['external_id' => 'txn-keep']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox.plaid.com/item/remove'
            && $request['access_token'] === 'access-sandbox-secret');
    }

    /**
     * @return array<string, mixed>
     */
    protected function plaidTransaction(
        string $id,
        string $accountId,
        float $amount,
        string $date,
        string $description,
        bool $pending = false,
        ?string $pendingId = null,
    ): array {
        return [
            'transaction_id' => $id,
            'account_id' => $accountId,
            'amount' => $amount,
            'iso_currency_code' => 'USD',
            'date' => $date,
            'authorized_date' => $date,
            'name' => $description,
            'original_description' => $description,
            'pending' => $pending,
            'pending_transaction_id' => $pendingId,
            'personal_finance_category' => [
                'primary' => 'FOOD_AND_DRINK',
                'detailed' => 'FOOD_AND_DRINK_COFFEE',
                'confidence_level' => 'HIGH',
            ],
        ];
    }
}
