<?php

namespace App\Services\Plaid;

use App\Models\PlaidAccount;
use App\Models\PlaidItem;
use App\Models\PlaidLinkEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PlaidLinkService
{
    public function __construct(
        protected PlaidClient $client,
        protected PlaidHistoryWindow $history,
    ) {}

    /**
     * @param  array{import_history: bool, import_from: ?string}  $choice
     * @return array{link_token: string, mode: string}
     */
    public function createLinkToken(User $user, array $choice): array
    {
        $window = $this->history->resolve(
            $choice['import_history'],
            $choice['import_from'],
            now(),
            config('services.plaid.env') === 'production',
        );

        $response = $this->client->createLinkToken((string) $user->id, $window);

        $linkToken = $response['link_token'] ?? null;

        if (! is_string($linkToken) || $linkToken === '') {
            throw new PlaidRequestException(null, 'LINK_TOKEN_MISSING', $response['request_id'] ?? null, null);
        }

        session([
            'plaid.link' => [
                'mode' => 'create',
                'link_token' => $linkToken,
                'days_requested' => $window['days_requested'],
                'import_from' => $window['import_from'],
                'item_id' => null,
            ],
        ]);

        return [
            'link_token' => $linkToken,
            'mode' => 'create',
        ];
    }

    /**
     * @return array{link_token: string, mode: string, item_id: string}
     */
    public function createUpdateLinkToken(PlaidItem $item): array
    {
        $response = $this->client->createLinkToken((string) $item->user_id, [
            'days_requested' => $item->days_requested,
            'import_from' => $item->import_from->toDateString(),
        ], $item->access_token);

        $linkToken = $response['link_token'] ?? null;

        if (! is_string($linkToken) || $linkToken === '') {
            throw new PlaidRequestException(null, 'LINK_TOKEN_MISSING', $response['request_id'] ?? null, null);
        }

        session([
            'plaid.link' => [
                'mode' => 'update',
                'link_token' => $linkToken,
                'days_requested' => $item->days_requested,
                'import_from' => $item->import_from->toDateString(),
                'item_id' => $item->id,
            ],
        ]);

        return [
            'link_token' => $linkToken,
            'mode' => 'update',
            'item_id' => $item->id,
        ];
    }

    public function exchange(User $user, string $publicToken, ?string $institutionId, ?string $linkSessionId): PlaidItem
    {
        $pending = session('plaid.link');

        if (! is_array($pending) || ($pending['mode'] ?? null) !== 'create') {
            throw ValidationException::withMessages([
                'public_token' => 'Start from Add a bank account so the history choice is saved.',
            ]);
        }

        if (! is_string($institutionId) || $institutionId === '') {
            throw ValidationException::withMessages([
                'institution_id' => 'Plaid did not identify the bank.',
            ]);
        }

        $duplicate = PlaidItem::query()
            ->where('user_id', $user->id)
            ->where('institution_id', $institutionId)
            ->first();

        if ($duplicate instanceof PlaidItem) {
            throw ValidationException::withMessages([
                'institution_id' => 'That bank is already connected. Reconnect it instead of adding it again.',
            ]);
        }

        $exchanged = $this->client->exchangePublicToken($publicToken);
        $accessToken = $exchanged['access_token'] ?? null;
        $itemId = $exchanged['item_id'] ?? null;

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($itemId) || $itemId === '') {
            throw new PlaidRequestException(null, 'EXCHANGE_INCOMPLETE', is_string($exchanged['request_id'] ?? null) ? $exchanged['request_id'] : null, null);
        }

        $item = PlaidItem::query()->create([
            'user_id' => $user->id,
            'item_id' => $itemId,
            'access_token' => $accessToken,
            'institution_id' => $institutionId,
            'institution_name' => null,
            'link_session_id' => $linkSessionId,
            'days_requested' => $pending['days_requested'],
            'import_from' => $pending['import_from'],
        ]);

        try {
            $item->update([
                'institution_name' => $this->institutionName($institutionId),
            ]);
            $accounts = $this->client->accountsGet($accessToken);
            $this->storeAccounts($item, $accounts['accounts'] ?? []);
        } catch (PlaidRequestException $exception) {
            report($exception);
        }

        PlaidLinkEvent::query()->create([
            'user_id' => $user->id,
            'plaid_item_id' => $item->id,
            'link_session_id' => $linkSessionId,
            'event' => 'handoff',
            'institution_id' => $institutionId,
        ]);

        session()->forget('plaid.link');

        return $item;
    }

    public function markRepaired(PlaidItem $item, ?string $linkSessionId): void
    {
        $item->update([
            'error_code' => null,
            'link_session_id' => $linkSessionId ?: $item->link_session_id,
        ]);

        PlaidLinkEvent::query()->create([
            'user_id' => $item->user_id,
            'plaid_item_id' => $item->id,
            'link_session_id' => $linkSessionId,
            'event' => 'handoff',
            'institution_id' => $item->institution_id,
        ]);

        session()->forget('plaid.link');
    }

    public function recordExit(User $user, ?string $linkSessionId, ?string $institutionId, ?string $errorType, ?string $errorCode, ?string $requestId): void
    {
        PlaidLinkEvent::query()->create([
            'user_id' => $user->id,
            'link_session_id' => $linkSessionId,
            'event' => 'exit',
            'institution_id' => $institutionId,
            'error_type' => $errorType,
            'error_code' => $errorCode,
            'request_id' => $requestId,
        ]);
    }

    public function remove(PlaidItem $item): void
    {
        try {
            $this->client->itemRemove($item->access_token);
        } catch (PlaidRequestException $exception) {
            if ($exception->errorCode !== 'ITEM_NOT_FOUND') {
                throw $exception;
            }
        }

        $item->delete();
    }

    protected function institutionName(string $institutionId): ?string
    {
        try {
            $response = $this->client->institutionGetById($institutionId);
        } catch (PlaidRequestException) {
            return null;
        }

        $name = $response['institution']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @param  list<array<string, mixed>>|mixed  $accounts
     */
    public function storeAccounts(PlaidItem $item, mixed $accounts): void
    {
        if (! is_array($accounts)) {
            return;
        }

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $accountId = $account['account_id'] ?? null;

            if (! is_string($accountId) || $accountId === '') {
                continue;
            }

            $mask = $account['mask'] ?? null;

            PlaidAccount::query()->updateOrCreate(
                [
                    'plaid_item_id' => $item->id,
                    'plaid_account_id' => $accountId,
                ],
                [
                    'persistent_account_id' => is_string($account['persistent_account_id'] ?? null)
                        ? $account['persistent_account_id']
                        : null,
                    'name' => is_string($account['name'] ?? null) ? $account['name'] : 'Account',
                    'official_name' => is_string($account['official_name'] ?? null) ? $account['official_name'] : null,
                    'mask' => is_string($mask) && preg_match('/^\d{4}$/', $mask) === 1 ? $mask : null,
                    'type' => is_string($account['type'] ?? null) ? $account['type'] : 'other',
                    'subtype' => is_string($account['subtype'] ?? null) ? $account['subtype'] : null,
                ],
            );
        }
    }
}
