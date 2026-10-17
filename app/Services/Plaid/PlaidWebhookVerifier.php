<?php

namespace App\Services\Plaid;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use stdClass;
use Throwable;

class PlaidWebhookVerifier
{
    public function __construct(protected PlaidClient $client) {}

    public function assertValid(string $token, string $rawBody): stdClass
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new PlaidRequestException(null, 'WEBHOOK_JWT_INVALID', null, null);
        }

        $header = json_decode($this->base64UrlDecode($parts[0]), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'ES256') {
            throw new PlaidRequestException(null, 'WEBHOOK_JWT_ALG', null, null);
        }

        $keyId = $header['kid'] ?? null;

        if (! is_string($keyId) || $keyId === '') {
            throw new PlaidRequestException(null, 'WEBHOOK_JWT_KID', null, null);
        }

        try {
            $decoded = JWT::decode($token, JWK::parseKeySet([
                'keys' => [$this->verificationKey($keyId)],
            ]));
        } catch (Throwable) {
            throw new PlaidRequestException(null, 'WEBHOOK_JWT_INVALID', null, null);
        }

        $issuedAt = $decoded->iat ?? null;

        if (! is_numeric($issuedAt) || (time() - (int) $issuedAt) > 300) {
            throw new PlaidRequestException(null, 'WEBHOOK_JWT_EXPIRED', null, null);
        }

        $expected = $decoded->request_body_sha256 ?? null;
        $actual = hash('sha256', $rawBody);

        if (! is_string($expected) || ! hash_equals($expected, $actual)) {
            throw new PlaidRequestException(null, 'WEBHOOK_BODY_MISMATCH', null, null);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function verificationKey(string $keyId): array
    {
        $cached = Cache::get($this->cacheKey($keyId));

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->client->webhookVerificationKey($keyId);
        $key = $response['key'] ?? null;

        if (! is_array($key)) {
            throw new PlaidRequestException(
                null,
                'WEBHOOK_KEY_MISSING',
                is_string($response['request_id'] ?? null) ? $response['request_id'] : null,
                null,
            );
        }

        $expiredAt = $key['expired_at'] ?? null;

        if (is_numeric($expiredAt) && (int) $expiredAt <= time()) {
            throw new PlaidRequestException(null, 'WEBHOOK_KEY_EXPIRED', null, null);
        }

        $jwk = array_intersect_key($key, array_flip(['kty', 'crv', 'x', 'y', 'kid', 'alg', 'use']));

        $ttl = is_numeric($expiredAt)
            ? max(1, (int) $expiredAt - time())
            : 60 * 60 * 24;

        Cache::put($this->cacheKey($keyId), $jwk, $ttl);

        return $jwk;
    }

    protected function cacheKey(string $keyId): string
    {
        return 'plaid.webhook_key.'.$keyId;
    }

    protected function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
