<?php

namespace App\Http\Controllers\ApiTokens;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $tokens = $user->tokens()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at->toIso8601String(),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ]);

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'account_type', 'last_four'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'last_four' => $account->last_four,
            ]);

        $merchants = Merchant::query()
            ->where('user_id', $user->id)
            ->where('supports_order_import', false)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Merchant $merchant): array => [
                'id' => $merchant->id,
                'name' => $merchant->name,
            ]);

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->where('kind', '!=', Category::KIND_INCOME)
            ->orderBy('kind')
            ->orderBy('name')
            ->get(['id', 'name', 'kind'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
            ]);

        return Inertia::render('ApiTokens/Index', [
            'tokens' => $tokens,
            'accounts' => $accounts,
            'merchants' => $merchants,
            'categories' => $categories,
            'endpoint' => url('/api/pending-spends'),
            'plainTextToken' => $request->session()->pull('plainTextToken'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = $validated['name'];

        $request->user()->tokens()->where('name', $name)->delete();

        $token = $request->user()->createToken($name, ['pending-spend:create']);

        return redirect()
            ->route('api-tokens.index')
            ->with('plainTextToken', $token->plainTextToken)
            ->with('success', 'Copy this token now. It will not be shown again.');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $deleted = $request->user()->tokens()->whereKey($token)->delete();

        abort_unless($deleted === 1, 404);

        return redirect()
            ->route('api-tokens.index')
            ->with('success', 'Token revoked.');
    }
}
