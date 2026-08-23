<?php

namespace Tests\Feature\ApiTokens;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiTokenPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_api_tokens(): void
    {
        $this->get(route('api-tokens.pending-spend'))
            ->assertRedirect('/login');

        $this->get(route('api-tokens.leftover-reporting'))
            ->assertRedirect('/login');

        $this->get(route('api-tokens.retailer-scraper'))
            ->assertRedirect('/login');
    }

    public function test_guests_cannot_mint_tokens(): void
    {
        $this->post(route('api-tokens.pending-spend.store'), [
            'name' => 'iPhone Shortcut',
        ])->assertRedirect('/login');

        $this->post(route('api-tokens.leftover-reporting.store'), [
            'name' => 'Leftover reporting',
        ])->assertRedirect('/login');

        $this->post(route('api-tokens.retailer-scraper.store'), [
            'name' => 'Retailer scraper',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_pending_spend_page_lists_only_pending_spend_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('Amazon Chrome Extension:abc', ['amazon:import']);
        $user->createToken('iPhone Shortcut', ['pending-spend:create']);
        $user->createToken('Leftover reporting', ['leftover:read']);

        $this->actingAs($user)
            ->get(route('api-tokens.pending-spend'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/PendingSpend')
                ->where('plainTextToken', null)
                ->has('tokens', 1)
                ->where('tokens.0.name', 'iPhone Shortcut')
                ->where('tokens.0.abilities', ['pending-spend:create'])
                ->has('endpoint')
                ->missing('tokens.0.token')
                ->missing('tokens.0.plainTextToken'));
    }

    public function test_leftover_reporting_page_lists_only_leftover_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('Amazon Chrome Extension:abc', ['amazon:import']);
        $user->createToken('iPhone Shortcut', ['pending-spend:create']);
        $user->createToken('Leftover reporting', ['leftover:read']);

        $this->actingAs($user)
            ->get(route('api-tokens.leftover-reporting'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/LeftoverReporting')
                ->where('plainTextToken', null)
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Leftover reporting')
                ->where('tokens.0.abilities', ['leftover:read'])
                ->has('endpoint')
                ->missing('tokens.0.token')
                ->missing('tokens.0.plainTextToken'));
    }

    public function test_retailer_scraper_page_lists_only_scraper_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('Amazon Chrome Extension:abc', ['amazon:import']);
        $user->createToken('iPhone Shortcut', ['pending-spend:create']);
        $user->createToken('Leftover reporting', ['leftover:read']);

        $this->actingAs($user)
            ->get(route('api-tokens.retailer-scraper'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/RetailerScraper')
                ->where('plainTextToken', null)
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Amazon Chrome Extension:abc')
                ->where('tokens.0.abilities', ['amazon:import'])
                ->has('endpoint')
                ->has('statusEndpoint')
                ->missing('tokens.0.token')
                ->missing('tokens.0.plainTextToken'));
    }

    public function test_pending_spend_page_does_not_list_lookup_tables(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('api-tokens.pending-spend'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/PendingSpend')
                ->has('endpoint')
                ->missing('accounts')
                ->missing('merchants')
                ->missing('categories'));
    }

    public function test_authenticated_user_can_mint_a_pending_spend_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('api-tokens.pending-spend.store'), [
            'name' => 'iPhone Shortcut',
        ]);

        $response
            ->assertRedirect(route('api-tokens.pending-spend'))
            ->assertSessionHas('plainTextToken')
            ->assertSessionHas('success');

        $plainTextToken = session('plainTextToken');
        $this->assertIsString($plainTextToken);
        $this->assertStringContainsString('|', $plainTextToken);

        $token = $user->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame('iPhone Shortcut', $token->name);
        $this->assertSame(['pending-spend:create'], $token->abilities);
        $this->assertNull($token->expires_at);

        $this->actingAs($user)
            ->get(route('api-tokens.pending-spend'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/PendingSpend')
                ->where('plainTextToken', $plainTextToken)
                ->has('tokens', 1)
                ->where('tokens.0.name', 'iPhone Shortcut'));
    }

    public function test_authenticated_user_can_mint_a_leftover_reporting_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('api-tokens.leftover-reporting.store'), [
            'name' => 'Leftover reporting',
        ]);

        $response
            ->assertRedirect(route('api-tokens.leftover-reporting'))
            ->assertSessionHas('plainTextToken')
            ->assertSessionHas('success');

        $token = $user->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame('Leftover reporting', $token->name);
        $this->assertSame(['leftover:read'], $token->abilities);

        $this->actingAs($user)
            ->get(route('api-tokens.leftover-reporting'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/LeftoverReporting')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Leftover reporting')
                ->where('tokens.0.abilities', ['leftover:read']));
    }

    public function test_authenticated_user_can_mint_a_retailer_scraper_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('api-tokens.retailer-scraper.store'), [
            'name' => 'Retailer scraper',
        ]);

        $response
            ->assertRedirect(route('api-tokens.retailer-scraper'))
            ->assertSessionHas('plainTextToken')
            ->assertSessionHas('success');

        $token = $user->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame('Retailer scraper', $token->name);
        $this->assertSame(['amazon:import'], $token->abilities);

        $this->actingAs($user)
            ->get(route('api-tokens.retailer-scraper'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ApiTokens/RetailerScraper')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'Retailer scraper')
                ->where('tokens.0.abilities', ['amazon:import']));
    }

    public function test_minting_replaces_a_token_with_the_same_name(): void
    {
        $user = User::factory()->create();
        $existing = $user->createToken('iPhone Shortcut', ['pending-spend:create']);

        $this->actingAs($user)->post(route('api-tokens.pending-spend.store'), [
            'name' => 'iPhone Shortcut',
        ])->assertRedirect(route('api-tokens.pending-spend'));

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotSame(
            $existing->accessToken->id,
            $user->tokens()->first()?->id,
        );
    }

    public function test_authenticated_user_can_revoke_their_pending_spend_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('iPhone Shortcut', ['pending-spend:create']);

        $this->actingAs($user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id))
            ->assertRedirect(route('api-tokens.pending-spend'))
            ->assertSessionHas('success');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_authenticated_user_can_revoke_their_leftover_reporting_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Leftover reporting', ['leftover:read']);

        $this->actingAs($user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id))
            ->assertRedirect(route('api-tokens.leftover-reporting'))
            ->assertSessionHas('success');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_authenticated_user_can_revoke_their_retailer_scraper_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Amazon Chrome Extension:abc', ['amazon:import']);

        $this->actingAs($user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id))
            ->assertRedirect(route('api-tokens.retailer-scraper'))
            ->assertSessionHas('success');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_user_cannot_revoke_another_users_token(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $other->createToken('iPhone Shortcut', ['pending-spend:create']);

        $this->actingAs($user)
            ->delete(route('api-tokens.destroy', $token->accessToken->id))
            ->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
    }
}
