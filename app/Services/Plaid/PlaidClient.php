<?php

namespace App\Services\Plaid;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlaidClient
{
    public const VERSION = '2020-09-14';

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        $clientId = config('services.plaid.client_id');
        $secret = config('services.plaid.secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($secret) || $secret === '') {
            throw new PlaidRequestException(null, 'PLAID_NOT_CONFIGURED', null, null);
        }

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Plaid-Version' => self::VERSION,
            ])
            ->post($path, [
                'client_id' => $clientId,
                'secret' => $secret,
                ...$body,
            ]);

        return $this->decode($path, $response);
    }

    /**
     * @param  array{import_from: string, days_requested: int}  $history
     * @return array<string, mixed>
     */
    public function createLinkToken(string $clientUserId, array $history, ?string $accessToken = null): array
    {
        $body = [
            'client_name' => (string) config('app.name', 'Spendable'),
            'language' => 'en',
            'country_codes' => ['US'],
            'user' => [
                'client_user_id' => $clientUserId,
            ],
        ];

        if ($accessToken === null) {
            $body['products'] = ['transactions'];
            $body['transactions'] = [
                'days_requested' => $history['days_requested'],
            ];
        } else {
            $body['access_token'] = $accessToken;
            $body['products'] = [];
        }

        $webhook = config('services.plaid.webhook_url');
        $redirect = config('services.plaid.redirect_uri');

        if (is_string($webhook) && $webhook !== '') {
            $body['webhook'] = $webhook;
        }

        if (is_string($redirect) && $redirect !== '') {
            $body['redirect_uri'] = $redirect;
        }

        return $this->post('/link/token/create', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function exchangePublicToken(string $publicToken): array
    {
        return $this->post('/item/public_token/exchange', [
            'public_token' => $publicToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function accountsGet(string $accessToken): array
    {
        return $this->post('/accounts/get', [
            'access_token' => $accessToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function institutionGetById(string $institutionId): array
    {
        return $this->post('/institutions/get_by_id', [
            'institution_id' => $institutionId,
            'country_codes' => ['US'],
            'options' => [
                'include_optional_metadata' => true,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionsSync(string $accessToken, ?string $cursor): array
    {
        $body = [
            'access_token' => $accessToken,
            'count' => 500,
            'options' => [
                'include_original_description' => true,
                'personal_finance_category_version' => 'v2',
            ],
        ];

        if (is_string($cursor) && $cursor !== '') {
            $body['cursor'] = $cursor;
        }

        return $this->post('/transactions/sync', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function itemRemove(string $accessToken): array
    {
        return $this->post('/item/remove', [
            'access_token' => $accessToken,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function webhookVerificationKey(string $keyId): array
    {
        return $this->post('/webhook_verification_key/get', [
            'key_id' => $keyId,
        ]);
    }

    public function baseUrl(): string
    {
        $environment = config('services.plaid.env', 'sandbox');

        return match ($environment) {
            'sandbox' => 'https://sandbox.plaid.com',
            'production' => 'https://production.plaid.com',
            default => throw new PlaidRequestException(null, 'PLAID_ENV_INVALID', null, null),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(string $path, Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            $json = [];
        }

        $requestId = is_string($json['request_id'] ?? null) ? $json['request_id'] : null;

        if ($response->failed()) {
            Log::warning('Plaid request failed', [
                'path' => $path,
                'request_id' => $requestId,
                'error_type' => $json['error_type'] ?? null,
                'error_code' => $json['error_code'] ?? null,
            ]);

            throw new PlaidRequestException(
                is_string($json['error_type'] ?? null) ? $json['error_type'] : null,
                is_string($json['error_code'] ?? null) ? $json['error_code'] : null,
                $requestId,
                is_string($json['display_message'] ?? null) ? $json['display_message'] : null,
            );
        }

        Log::info('Plaid request completed', [
            'path' => $path,
            'request_id' => $requestId,
        ]);

        return $json;
    }
}
