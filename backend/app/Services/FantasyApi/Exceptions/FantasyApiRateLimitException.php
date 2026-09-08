<?php

namespace App\Services\FantasyApi\Exceptions;

class FantasyApiRateLimitException extends FantasyApiException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, 429);
    }
}
