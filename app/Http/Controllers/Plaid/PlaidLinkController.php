<?php

namespace App\Http\Controllers\Plaid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plaid\StorePlaidLinkExitRequest;
use App\Http\Requests\Plaid\StorePlaidLinkTokenRequest;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidLinkService;
use App\Services\Plaid\PlaidRequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlaidLinkController extends Controller
{
    public function store(StorePlaidLinkTokenRequest $request, PlaidLinkService $links): JsonResponse
    {
        return $this->tokenResponse(fn () => $links->createLinkToken($request->user(), [
            'import_history' => $request->boolean('import_history'),
            'import_from' => $request->input('import_from'),
        ]));
    }

    public function update(Request $request, PlaidItem $item, PlaidLinkService $links): JsonResponse
    {
        $this->authorize('update', $item);

        return $this->tokenResponse(fn () => $links->createUpdateLinkToken($item));
    }

    public function oauth(): Response|RedirectResponse
    {
        $pending = session('plaid.link');

        if (! is_array($pending) || ! is_string($pending['link_token'] ?? null)) {
            return redirect()
                ->route('accounts.index')
                ->with('error', 'That bank connection expired. Start again.');
        }

        return Inertia::render('Plaid/OAuth', [
            'linkToken' => $pending['link_token'],
            'mode' => $pending['mode'] ?? 'create',
            'itemId' => $pending['item_id'],
        ]);
    }

    public function exit(StorePlaidLinkExitRequest $request, PlaidLinkService $links): JsonResponse
    {
        $links->recordExit(
            $request->user(),
            $request->input('link_session_id'),
            $request->input('institution_id'),
            $request->input('error_type'),
            $request->input('error_code'),
            $request->input('request_id'),
        );

        return response()->json(['ok' => true]);
    }

    /**
     * @param  callable(): array<string, mixed>  $create
     */
    protected function tokenResponse(callable $create): JsonResponse
    {
        try {
            return response()->json($create());
        } catch (PlaidRequestException $exception) {
            $message = $exception->errorCode === 'PLAID_NOT_CONFIGURED'
                ? 'Plaid keys are not configured on the server.'
                : $exception->userMessage();

            return response()->json(['message' => $message], 422);
        }
    }
}
