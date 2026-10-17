<?php

namespace App\Http\Controllers\Plaid;

use App\Http\Controllers\Controller;
use App\Jobs\SyncPlaidTransactions;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidRequestException;
use App\Services\Plaid\PlaidWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlaidWebhookController extends Controller
{
    public function store(Request $request, PlaidWebhookVerifier $verifier): Response
    {
        $header = $request->headers->get('Plaid-Verification')
            ?? $request->headers->get('plaid-verification')
            ?? '';

        try {
            $verifier->assertValid($header, $request->getContent());
        } catch (PlaidRequestException) {
            return response('', 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('', 200);
        }

        $itemId = $payload['item_id'] ?? null;
        $item = is_string($itemId)
            ? PlaidItem::query()->where('item_id', $itemId)->first()
            : null;

        if ($item instanceof PlaidItem) {
            $this->apply($item, $payload);
        }

        return response('', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function apply(PlaidItem $item, array $payload): void
    {
        $type = $payload['webhook_type'] ?? null;
        $code = $payload['webhook_code'] ?? null;

        if ($type === 'TRANSACTIONS' && $code === 'SYNC_UPDATES_AVAILABLE') {
            SyncPlaidTransactions::dispatch($item->id);

            return;
        }

        if ($type !== 'ITEM') {
            return;
        }

        if ($code === 'ERROR') {
            $errorCode = $payload['error']['error_code'] ?? null;

            if (is_string($errorCode) && $errorCode !== '') {
                $item->update(['error_code' => $errorCode]);
            }

            return;
        }

        if (in_array($code, ['PENDING_DISCONNECT', 'PENDING_EXPIRATION', 'USER_PERMISSION_REVOKED'], true)) {
            $item->update(['error_code' => $code]);

            return;
        }

        if ($code === 'LOGIN_REPAIRED') {
            $item->update(['error_code' => null]);
        }
    }
}
