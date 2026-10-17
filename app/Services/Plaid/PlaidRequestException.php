<?php

namespace App\Services\Plaid;

use RuntimeException;

class PlaidRequestException extends RuntimeException
{
    public function __construct(
        public readonly ?string $errorType,
        public readonly ?string $errorCode,
        public readonly ?string $requestId,
        public readonly ?string $displayMessage,
    ) {
        parent::__construct($errorCode ?? 'PLAID_REQUEST_FAILED');
    }

    public function shouldRetry(): bool
    {
        return in_array($this->errorCode, [
            'INSTITUTION_DOWN',
            'INSTITUTION_NOT_RESPONDING',
            'PRODUCT_NOT_READY',
            'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION',
        ], true) || $this->errorType === 'RATE_LIMIT_EXCEEDED';
    }

    public function userMessage(): string
    {
        if (is_string($this->displayMessage) && $this->displayMessage !== '') {
            return $this->displayMessage;
        }

        return 'Plaid could not complete that request. Try again.';
    }
}
