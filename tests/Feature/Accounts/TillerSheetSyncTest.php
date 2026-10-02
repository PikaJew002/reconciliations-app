<?php

namespace Tests\Feature\Accounts;

use App\Models\TillerConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TillerSheetSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_from_tiller_sync(): void
    {
        Http::preventStrayRequests();

        $this->post(route('accounts.tiller-sync'))
            ->assertRedirect('/login');

        Http::assertNothingSent();
    }

    public function test_sync_requests_new_sheet_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true, 'sent' => 4], 200),
        ]);

        $user = User::factory()->create();
        TillerConnection::query()->create([
            'user_id' => $user->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);

        $this->actingAs($user)
            ->post(route('accounts.tiller-sync'))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('success', 'Tiller sync sent 4 transactions.');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://script.google.com/macros/s/abc/exec?key=sheet-secret';
        });
    }

    public function test_sync_reports_when_the_sheet_has_nothing_new(): void
    {
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true, 'sent' => 0], 200),
        ]);

        $user = User::factory()->create();
        TillerConnection::query()->create([
            'user_id' => $user->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);

        $this->actingAs($user)
            ->post(route('accounts.tiller-sync'))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('success', 'Tiller had no new transactions.');
    }

    public function test_sync_shows_the_script_error(): void
    {
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => false, 'error' => 'Unauthorized'], 200),
        ]);

        $user = User::factory()->create();
        TillerConnection::query()->create([
            'user_id' => $user->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);

        $this->actingAs($user)
            ->post(route('accounts.tiller-sync'))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('error', 'Unauthorized');

        Http::assertSentCount(1);
    }

    public function test_sync_without_a_connection_does_not_call_the_sheet(): void
    {
        Http::preventStrayRequests();

        $user = User::factory()->create();
        $other = User::factory()->create();
        TillerConnection::query()->create([
            'user_id' => $other->id,
            'callback_url' => 'https://script.google.com/macros/s/abc/exec',
            'webhook_secret' => 'sheet-secret',
        ]);

        $this->actingAs($user)
            ->post(route('accounts.tiller-sync'))
            ->assertRedirect(route('accounts.index'))
            ->assertSessionHas('error', 'Save a Tiller callback URL before syncing the sheet.');

        Http::assertNothingSent();
    }
}
