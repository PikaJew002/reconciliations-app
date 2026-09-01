<?php

namespace Tests\Feature\Review;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderComponent;
use App\Models\PendingSpend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewCategorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-30 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_review_can_recategorize_a_bank_charge_and_stay_on_the_slide(): void
    {
        [$user, $account, $batch] = $this->setupUser();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $groceries = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -24.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-26',
            'description' => 'Market',
        ]);

        $this->actingAs($user)
            ->post('/review/categorize', [
                'type' => 'bank',
                'id' => $transaction->id,
                'category_id' => $groceries->id,
                'week' => '2026-08-23',
                'item' => 'bank:'.$transaction->id,
                'act' => 'walk',
                'pass' => 'default',
            ])
            ->assertRedirect(route('review', [
                'week' => '2026-08-23',
                'item' => 'bank:'.$transaction->id,
                'act' => 'walk',
                'pass' => 'default',
            ]));

        $this->assertSame($groceries->id, $transaction->fresh()->category_id);
    }

    public function test_review_can_recategorize_pending_spend(): void
    {
        [$user, $account] = $this->setupUser();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $groceries = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);

        $pending = PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $dining->id,
            'amount' => 15.0,
            'spent_at' => '2026-08-27 12:00:00',
            'notes' => 'Store',
        ]);

        $this->actingAs($user)
            ->post('/review/categorize', [
                'type' => 'pending',
                'id' => $pending->id,
                'category_id' => $groceries->id,
                'week' => '2026-08-23',
                'item' => 'pending:'.$pending->id,
                'act' => 'walk',
            ])
            ->assertRedirect();

        $this->assertSame($groceries->id, $pending->fresh()->category_id);
    }

    public function test_review_can_recategorize_an_order_component(): void
    {
        [$user, $account, $batch] = $this->setupUser();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);
        $groceries = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);
        $merchant = Merchant::factory()->create([
            'user_id' => $user->id,
            'supports_order_import' => true,
            'name' => 'Amazon',
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $merchant->id,
            'import_batch_id' => $batch->id,
            'ordered_at' => '2026-08-25',
        ]);
        $component = OrderComponent::factory()->create([
            'order_id' => $order->id,
            'order_item_id' => null,
            'description' => 'Paper',
            'amount' => 20.0,
            'category_id' => $dining->id,
        ]);

        $this->actingAs($user)
            ->post('/review/categorize', [
                'type' => 'order_component',
                'id' => $component->id,
                'category_id' => $groceries->id,
                'week' => '2026-08-23',
                'item' => 'order_component:'.$component->id,
                'act' => 'walk',
            ])
            ->assertRedirect();

        $this->assertSame($groceries->id, $component->fresh()->category_id);
        $this->assertTrue($component->fresh()->is_user_modified);
    }

    public function test_review_can_clear_a_category(): void
    {
        [$user, $account, $batch] = $this->setupUser();
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -24.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-26',
            'description' => 'Market',
        ]);

        $this->actingAs($user)
            ->post('/review/categorize', [
                'type' => 'bank',
                'id' => $transaction->id,
                'category_id' => null,
                'week' => '2026-08-23',
                'item' => 'bank:'.$transaction->id,
                'act' => 'walk',
            ])
            ->assertRedirect();

        $this->assertNull($transaction->fresh()->category_id);
        $this->assertSame(BankTransaction::CLASSIFICATION_EXPENSE, $transaction->fresh()->classification);
    }

    public function test_review_cannot_categorize_another_users_charge(): void
    {
        [$user] = $this->setupUser();
        $other = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $other->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $other->id]);
        $dining = Category::factory()->for($other)->expense()->create(['name' => 'Dining']);
        $mine = Category::factory()->for($user)->expense()->create(['name' => 'Groceries']);

        $transaction = BankTransaction::factory()->create([
            'user_id' => $other->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -10.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-26',
        ]);

        $this->actingAs($user)
            ->post('/review/categorize', [
                'type' => 'bank',
                'id' => $transaction->id,
                'category_id' => $mine->id,
                'week' => '2026-08-23',
                'item' => 'bank:'.$transaction->id,
                'act' => 'walk',
            ])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Account, 2: ImportBatch}
     */
    protected function setupUser(): array
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);

        return [$user, $account, $batch];
    }
}
