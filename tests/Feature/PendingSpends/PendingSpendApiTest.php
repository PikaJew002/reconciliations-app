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
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
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
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
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
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
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
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
            'merchant_id' => $amazon->id,
            'spent_at' => '2026-08-10 18:30:00',
            'amount' => 40.00,
        ])->assertUnprocessable()
            ->assertJson([
                'message' => 'Order-import merchants are tracked via orders, not pending spend.',
            ]);

        $this->assertSame(0, PendingSpend::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'account_id' => '11111111-1111-1111-1111-111111111111',
            'source' => PendingSpend::SOURCE_DEBIT_CARD,
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
