<?php

namespace App\Http\Controllers\ApiTokens;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public const ABILITY_PENDING_SPEND = 'pending-spend:create';

    public const ABILITY_LEFTOVER_REPORTING = 'leftover:read';

    public const ABILITY_RETAILER_SCRAPER = 'amazon:import';

    public function pendingSpend(Request $request): Response
    {
        return Inertia::render('ApiTokens/PendingSpend', [
            'tokens' => $this->tokensForAbility($request->user(), self::ABILITY_PENDING_SPEND),
            'endpoint' => url('/api/pending-spends'),
            'plainTextToken' => $request->session()->pull('plainTextToken'),
        ]);
    }

    public function retailerScraper(Request $request): Response
    {
        return Inertia::render('ApiTokens/RetailerScraper', [
            'tokens' => $this->tokensForAbility($request->user(), self::ABILITY_RETAILER_SCRAPER),
            'endpoint' => url('/api/amazon/import'),
            'statusEndpoint' => url('/api/amazon/orders/status'),
            'plainTextToken' => $request->session()->pull('plainTextToken'),
        ]);
    }

    public function leftoverReporting(Request $request): Response
    {
        return Inertia::render('ApiTokens/LeftoverReporting', [
            'tokens' => $this->tokensForAbility($request->user(), self::ABILITY_LEFTOVER_REPORTING),
            'endpoint' => url('/api/leftover/current'),
            'plainTextToken' => $request->session()->pull('plainTextToken'),
        ]);
    }

    public function storePendingSpend(Request $request): RedirectResponse
    {
        return $this->mintToken($request, self::ABILITY_PENDING_SPEND, 'api-tokens.pending-spend');
    }

    public function storeLeftoverReporting(Request $request): RedirectResponse
    {
        return $this->mintToken($request, self::ABILITY_LEFTOVER_REPORTING, 'api-tokens.leftover-reporting');
    }

    public function storeRetailerScraper(Request $request): RedirectResponse
    {
        return $this->mintToken($request, self::ABILITY_RETAILER_SCRAPER, 'api-tokens.retailer-scraper');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $tokenModel = $request->user()->tokens()->whereKey($token)->first();

        if (! $tokenModel instanceof PersonalAccessToken) {
            abort(404);
        }

        $redirectRoute = match (true) {
            $tokenModel->can(self::ABILITY_RETAILER_SCRAPER) => 'api-tokens.retailer-scraper',
            $tokenModel->can(self::ABILITY_LEFTOVER_REPORTING) => 'api-tokens.leftover-reporting',
            default => 'api-tokens.pending-spend',
        };

        $tokenModel->delete();

        return redirect()
            ->route($redirectRoute)
            ->with('success', 'Token revoked.');
    }

    private function mintToken(Request $request, string $ability, string $redirectRoute): RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = $validated['name'];

        $request->user()->tokens()->where('name', $name)->delete();

        $token = $request->user()->createToken($name, [$ability]);

        return redirect()
            ->route($redirectRoute)
            ->with('plainTextToken', $token->plainTextToken)
            ->with('success', 'Copy this token now. It will not be shown again.');
    }

    /**
     * @return list<array{id: int, name: string, abilities: list<string>, last_used_at: ?string, created_at: string, expires_at: ?string}>
     */
    private function tokensForAbility(User $user, string $ability): array
    {
        return $user->tokens()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => $token->can($ability))
            ->values()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])
            ->all();
    }
}
