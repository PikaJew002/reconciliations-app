<?php

namespace Tests\Feature\Imports;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmazonOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson(route('api.amazon.orders.status'), [
            'orderNumbers' => ['111-0000001-0000001'],
        ])->assertUnauthorized();
    }

    public function test_tokens_without_import_ability_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['other']);

        $this->postJson(route('api.amazon.orders.status'), [
            'orderNumbers' => ['111-0000001-0000001'],
        ])->assertForbidden();
    }

    public function test_order_numbers_are_required(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->postJson(route('api.amazon.orders.status'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('orderNumbers');
    }

    public function test_returns_success_for_existing_orders_and_pending_for_the_rest(): void
    {
        $user = User::factory()->create();
        $amazon = $this->amazonMerchant($user);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => '111-0000001-0000001',
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $amazon->id,
            'order_number' => '111-0000002-0000002',
            'status' => 'reconciled',
        ]);

        Sanctum::actingAs($user, ['amazon:import']);

        $this->postJson(route('api.amazon.orders.status'), [
            'orderNumbers' => [
                '111-0000002-0000002',
                '111-0000001-0000001',
                '111-0000003-0000003',
                '111-0000001-0000001',
            ],
        ])->assertOk()
            ->assertExactJson([
                'orders' => [
                    [
                        'orderNumber' => '111-0000002-0000002',
                        'status' => 'success',
                    ],
                    [
                        'orderNumber' => '111-0000001-0000001',
                        'status' => 'success',
                    ],
                    [
                        'orderNumber' => '111-0000003-0000003',
                        'status' => 'pending',
                    ],
                ],
            ]);
    }

    public function test_does_not_report_other_users_or_merchants_orders_as_success(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $amazon = $this->amazonMerchant($user);
        $otherAmazon = $this->amazonMerchant($otherUser);
        $walmart = Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Walmart',
            'normalized_name' => 'walmart',
        ]);

        Order::factory()->create([
            'user_id' => $otherUser->id,
            'merchant_id' => $otherAmazon->id,
            'order_number' => '111-0000001-0000001',
            'status' => 'imported',
        ]);

        Order::factory()->create([
            'user_id' => $user->id,
            'merchant_id' => $walmart->id,
            'order_number' => '111-0000002-0000002',
            'status' => 'imported',
        ]);

        Sanctum::actingAs($user, ['amazon:import']);

        $this->postJson(route('api.amazon.orders.status'), [
            'orderNumbers' => [
                '111-0000001-0000001',
                '111-0000002-0000002',
            ],
        ])->assertOk()
            ->assertExactJson([
                'orders' => [
                    [
                        'orderNumber' => '111-0000001-0000001',
                        'status' => 'pending',
                    ],
                    [
                        'orderNumber' => '111-0000002-0000002',
                        'status' => 'pending',
                    ],
                ],
            ]);
    }

    public function test_all_orders_are_pending_when_the_user_has_no_amazon_merchant(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['amazon:import']);

        $this->postJson(route('api.amazon.orders.status'), [
            'orderNumbers' => ['111-0000001-0000001'],
        ])->assertOk()
            ->assertExactJson([
                'orders' => [
                    [
                        'orderNumber' => '111-0000001-0000001',
                        'status' => 'pending',
                    ],
                ],
            ]);
    }

    protected function amazonMerchant(User $user): Merchant
    {
        return Merchant::factory()->create([
            'user_id' => $user->id,
            'name' => 'Amazon',
            'normalized_name' => 'amazon',
            'type' => Merchant::RETAILER,
        ]);
    }
}
