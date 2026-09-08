<?php

namespace App\Services\FantasyApi\Exceptions;

use Exception;

class FantasyApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly mixed $responseBody = null,
    ) {
        parent::__construct($message);
    }
}
