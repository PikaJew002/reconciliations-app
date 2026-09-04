<?php

namespace Tests\Feature\Review;

use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\PendingSpend;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewPageTest extends TestCase
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

    public function test_guests_are_redirected_from_review_to_login(): void
    {
        $this->get('/review')
            ->assertRedirect('/login');

        $this->get('/review/sunday')
            ->assertRedirect('/login');
    }

    public function test_review_opens_leftover_without_sunday_query_params(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Leftover')
                ->where('windows', [])
                ->where('selected_occurrence_id', null)
                ->where('leftover_origin', null));
    }

    public function test_sunday_query_on_review_redirects_to_sunday(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/review?act=walk&item=bank:1&week=2026-08-23&pass=default')
            ->assertRedirect(route('review.sunday', [
                'week' => '2026-08-23',
                'act' => 'walk',
                'item' => 'bank:1',
                'pass' => 'default',
            ]));
    }

    public function test_review_opens_the_last_complete_week_with_walk_slides(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        $coffee = BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -12.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-26',
            'description' => 'Coffee',
        ]);
        PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $dining->id,
            'amount' => 9.0,
            'spent_at' => '2026-08-28 12:00:00',
            'notes' => 'Lunch',
        ]);
        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -40.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-16',
            'description' => 'Outside the week',
        ]);

        $this->actingAs($user)
            ->get('/review/sunday')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Show')
                ->where('act', 'open')
                ->where('week.week', '2026-08-23')
                ->where('week.label', 'Aug 23 – 29')
                ->where('month', '2026-08')
                ->where('week_spend', 21)
                ->has('slides', 2)
                ->where('slides.0.id', 'bank:'.$coffee->id)
                ->where('slides.1.kind', 'pending')
                ->where('item', 'bank:'.$coffee->id)
                ->has('month_summary')
                ->has('pace')
                ->has('categories', 1));
    }

    public function test_walk_act_keeps_the_requested_item(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $batch = ImportBatch::factory()->create(['user_id' => $user->id]);
        $dining = Category::factory()->for($user)->expense()->create(['name' => 'Dining']);

        BankTransaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'import_batch_id' => $batch->id,
            'amount' => -12.0,
            'classification' => BankTransaction::CLASSIFICATION_EXPENSE,
            'category_id' => $dining->id,
            'posted_at' => '2026-08-26',
            'description' => 'Coffee',
        ]);
        $lunch = PendingSpend::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $dining->id,
            'amount' => 9.0,
            'spent_at' => '2026-08-28 12:00:00',
            'notes' => 'Lunch',
        ]);

        $this->actingAs($user)
            ->get('/review/sunday?act=walk&item=pending:'.$lunch->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Show')
                ->where('act', 'walk')
                ->where('item', 'pending:'.$lunch->id));
    }
}
