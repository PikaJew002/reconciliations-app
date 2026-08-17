<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ExtensionAuthController extends Controller
{
    public function start(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'redirect_uri' => ['required', 'string'],
            'client_id' => ['required', 'uuid'],
        ]);

        $request->session()->put('extension_redirect', $validated['redirect_uri']);
        $request->session()->put('extension_client_id', $validated['client_id']);

        if ($request->user()) {
            return redirect()->route('extension.auth.callback');
        }

        return redirect()->route('login');
    }

    public function callback(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $redirectUri = $request->session()->pull('extension_redirect');
        $clientId = $request->session()->pull('extension_client_id');

        abort_unless($redirectUri && $clientId, 400);

        $tokenName = $this->tokenName($clientId);

        $user->tokens()->where('name', $tokenName)->delete();

        $token = $user->createToken(
            $tokenName,
            ['amazon:import'],
            now()->addMonths(6)
        );

        $url = $redirectUri.'?'.http_build_query([
            'token' => $token->plainTextToken,
        ]);

        return Inertia::location($url);
    }

    private function tokenName(string $clientId): string
    {
        return 'Amazon Chrome Extension:'.$clientId;
    }
}
