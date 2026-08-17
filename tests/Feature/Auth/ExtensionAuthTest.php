<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT_URI = 'https://abcdefghijklmnopqrstuvwxyz.chromiumapp.org/';

    private const CLIENT_ID = '9d2c4e1a-7b3f-4a8e-9c1d-2e6f8a0b4c7d';

    public function test_guests_are_sent_to_login_with_the_extension_state_stored(): void
    {
        $response = $this->get($this->startUrl());

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('extension_redirect', self::REDIRECT_URI);
        $response->assertSessionHas('extension_client_id', self::CLIENT_ID);
    }

    public function test_extension_auth_requires_a_client_id(): void
    {
        $this->get('/extension/auth?redirect_uri='.urlencode(self::REDIRECT_URI))
            ->assertSessionHasErrors('client_id');
    }

    public function test_authenticated_users_skip_login_and_go_to_the_callback(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get($this->startUrl());

        $response->assertRedirect(route('extension.auth.callback'));
        $response->assertSessionHas('extension_redirect', self::REDIRECT_URI);
        $response->assertSessionHas('extension_client_id', self::CLIENT_ID);
    }

    public function test_login_redirects_to_the_extension_callback_when_the_session_has_a_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession($this->extensionSession())
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('extension.auth.callback'));
    }

    public function test_register_redirects_to_the_extension_callback_when_the_session_has_a_redirect(): void
    {
        $response = $this->withSession($this->extensionSession())
            ->post('/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('extension.auth.callback'));
    }

    public function test_authenticated_users_visiting_login_with_an_extension_redirect_go_to_the_callback(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession($this->extensionSession())
            ->get('/login');

        $response->assertRedirect(route('extension.auth.callback'));
    }

    public function test_callback_issues_a_plaintext_token_named_for_the_client_and_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession($this->extensionSession())
            ->get(route('extension.auth.callback'));

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['token'] ?? null);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'Amazon Chrome Extension:'.self::CLIENT_ID,
        ]);
        $this->assertFalse(session()->has('extension_redirect'));
        $this->assertFalse(session()->has('extension_client_id'));
    }

    public function test_callback_replaces_an_existing_token_for_the_same_client(): void
    {
        $user = User::factory()->create();
        $tokenName = 'Amazon Chrome Extension:'.self::CLIENT_ID;
        $existing = $user->createToken($tokenName, ['amazon:import']);

        $this->actingAs($user)
            ->withSession($this->extensionSession())
            ->get(route('extension.auth.callback'))
            ->assertRedirect();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $existing->accessToken->id,
        ]);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => $tokenName,
        ]);
    }

    public function test_callback_keeps_tokens_for_other_clients(): void
    {
        $user = User::factory()->create();
        $otherClientId = '11111111-2222-4333-8444-555555555555';
        $user->createToken('Amazon Chrome Extension:'.$otherClientId, ['amazon:import']);

        $this->actingAs($user)
            ->withSession($this->extensionSession())
            ->get(route('extension.auth.callback'))
            ->assertRedirect();

        $this->assertSame(2, $user->tokens()->count());
        $this->assertTrue($user->tokens()->where('name', 'Amazon Chrome Extension:'.$otherClientId)->exists());
        $this->assertTrue($user->tokens()->where('name', 'Amazon Chrome Extension:'.self::CLIENT_ID)->exists());
    }

    /**
     * @return array{extension_redirect: string, extension_client_id: string}
     */
    private function extensionSession(): array
    {
        return [
            'extension_redirect' => self::REDIRECT_URI,
            'extension_client_id' => self::CLIENT_ID,
        ];
    }

    private function startUrl(): string
    {
        return '/extension/auth?'.http_build_query([
            'redirect_uri' => self::REDIRECT_URI,
            'client_id' => self::CLIENT_ID,
        ]);
    }
}
