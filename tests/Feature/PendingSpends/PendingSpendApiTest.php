<?php

namespace Tests\Feature\PendingSpends;

use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\PendingSpend;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PendingSpendApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson(route('api.pending-spends.store'), $this->payload())
            ->assertUnauthorized();
    }

    public function test_tokens_without_create_ability_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['other']);

        $this->postJson(route('api.pending-spends.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_amazon_import_tokens_cannot_create_pending_spend(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->postJson(route('api.pending-spends.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_pending_spend_tokens_cannot_call_amazon_import(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['pending-spend:create']);

        $this->postJson(route('api.amazon.import'), [
            'details' => [],
        ])->assertForbidden();
    }

    public function test_authenticated_token_creates_pending_spend(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $response = $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'category_id' => $context['category']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.5,
            'notes' => 'Coffee',
        ]);

        $pending = PendingSpend::query()->first();

        $this->assertNotNull($pending);
        $this->assertSame($context['user']->id, $pending->user_id);
        $this->assertSame($context['account']->id, $pending->account_id);
        $this->assertSame($context['merchant']->id, $pending->merchant_id);
        $this->assertSame($context['category']->id, $pending->category_id);
        $this->assertSame(PendingSpend::SOURCE_DEBIT_CARD, $pending->source);
        $this->assertSame('12.50', $pending->amount);
        $this->assertSame(PendingSpend::STATUS_PENDING, $pending->status);
        $this->assertSame('Coffee', $pending->notes);

        $response
            ->assertCreated()
            ->assertJson([
                'id' => $pending->id,
                'account_id' => $context['account']->id,
                'source' => PendingSpend::SOURCE_DEBIT_CARD,
                'amount' => '12.50',
                'merchant_id' => $context['merchant']->id,
                'category_id' => $context['category']->id,
                'status' => PendingSpend::STATUS_PENDING,
                'notes' => 'Coffee',
            ]);
    }

    public function test_amount_is_required(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $context['account']->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, PendingSpend::query()->count());
    }

    public function test_foreign_account_is_rejected(): void
    {
        $context = $this->context();
        $otherAccount = Account::factory()->create([
            'account_type' => Account::CHECKING,
        ]);
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $otherAccount->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.5,
        ])->assertUnprocessable()
            ->assertJson([
                'message' => 'Account is required and must belong to the user.',
            ]);

        $this->assertSame(0, PendingSpend::query()->count());
    }

    public function test_order_import_merchant_is_rejected(): void
    {
        $context = $this->context();
        $amazon = Merchant::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $context['account']->id,
            'merchant_id' => $amazon->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 40.00,
        ])->assertUnprocessable()
            ->assertJson([
                'message' => 'Order-import merchants are tracked via orders, not pending spend.',
            ]);

        $this->assertSame(0, PendingSpend::query()->count());
    }

    public function test_checking_account_with_venmo_creates_venmo_source(): void
    {
        $context = $this->context();
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $context['account']->id,
            'venmo' => true,
            'spent_at' => '2026-08-10 19:11:00',
            'amount' => 250,
        ])->assertCreated()
            ->assertJson([
                'source' => PendingSpend::SOURCE_VENMO,
                'merchant_id' => null,
            ]);

        $this->assertSame(PendingSpend::SOURCE_VENMO, PendingSpend::query()->first()?->source);
    }

    public function test_credit_card_account_derives_credit_card_source(): void
    {
        $context = $this->context();
        $card = Account::factory()->create([
            'user_id' => $context['user']->id,
            'account_type' => Account::CREDIT_CARD,
        ]);
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $card->id,
            'merchant_id' => $context['merchant']->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.5,
        ])->assertCreated()
            ->assertJson([
                'account_id' => $card->id,
                'source' => PendingSpend::SOURCE_CREDIT_CARD,
            ]);
    }

    public function test_venmo_on_credit_card_is_rejected(): void
    {
        $context = $this->context();
        $card = Account::factory()->create([
            'user_id' => $context['user']->id,
            'account_type' => Account::CREDIT_CARD,
        ]);
        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->postJson(route('api.pending-spends.store'), [
            'account_id' => $card->id,
            'venmo' => true,
            'spent_at' => '2026-08-10 19:11:00',
            'amount' => 20,
        ])->assertUnprocessable()
            ->assertJson([
                'message' => 'Venmo pending spend cannot use a credit card account.',
            ]);

        $this->assertSame(0, PendingSpend::query()->count());
    }

    public function test_unauthenticated_options_requests_are_rejected(): void
    {
        $this->getJson(route('api.pending-spends.options'))
            ->assertUnauthorized();
    }

    public function test_tokens_without_create_ability_cannot_list_options(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->getJson(route('api.pending-spends.options'))
            ->assertForbidden();
    }

    public function test_options_lists_expense_categories_merchants_and_accounts_as_name_to_id(): void
    {
        $context = $this->context();
        $groceries = Category::factory()->for($context['user'])->expense()->create([
            'name' => 'Groceries',
        ]);
        Category::factory()->for($context['user'])->bill()->create(['name' => 'Electric']);
        Category::factory()->for($context['user'])->income()->create(['name' => 'Paycheck']);
        Category::factory()->expense()->create(['name' => 'Someone else dining']);

        Merchant::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'supports_order_import' => true,
        ]);
        Merchant::factory()->create([
            'name' => 'Other merchant',
            'supports_order_import' => false,
        ]);
        $zebra = Merchant::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Zebra Cafe',
            'supports_order_import' => false,
        ]);

        $creditCard = Account::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Capital One',
            'account_type' => Account::CREDIT_CARD,
        ]);
        Account::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Emergency Savings',
            'account_type' => Account::SAVINGS,
        ]);
        Account::factory()->create([
            'user_id' => $context['user']->id,
            'name' => 'Wallet',
            'account_type' => Account::CASH,
        ]);
        Account::factory()->offBook()->create([
            'user_id' => $context['user']->id,
        ]);
        Account::factory()->create([
            'name' => 'Someone else checking',
            'account_type' => Account::CHECKING,
        ]);

        Sanctum::actingAs($context['user'], ['pending-spend:create']);

        $this->getJson(route('api.pending-spends.options'))
            ->assertOk()
            ->assertExactJson([
                'categories' => [
                    'Dining' => $context['category']->id,
                    'Groceries' => $groceries->id,
                ],
                'merchants' => [
                    "Buc-ee's" => $context['merchant']->id,
                    'Zebra Cafe' => $zebra->id,
                ],
                'accounts' => [
                    'Capital One' => $creditCard->id,
                    'CVNB Checking' => $context['account']->id,
                ],
            ]);
    }

    public function test_options_return_empty_objects_when_nothing_is_eligible(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['pending-spend:create']);

        $this->getJson(route('api.pending-spends.options'))
            ->assertOk()
            ->assertContent('{"categories":{},"merchants":{},"accounts":{}}');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'account_id' => '11111111-1111-1111-1111-111111111111',
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 12.5,
        ];
    }

    /**
     * @return array{user: User, account: Account, merchant: Merchant, category: Category}
     */
    protected function context(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'user_id' => $user->id,
            'name' => 'CVNB Checking',
            'account_type' => Account::CHECKING,
            'last_four' => '6218',
        ]);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => "Buc-ee's",
            'normalized_name' => 'buc ee',
            'supports_order_import' => false,
        ]);
        $category = Category::factory()->for($user)->expense()->create([
            'name' => 'Dining',
        ]);

        return compact('user', 'account', 'merchant', 'category');
    }
}
