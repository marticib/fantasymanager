<?php

namespace App\Services\FantasyApi\Exceptions;

/**
 * Thrown when we have no usable tokens, or LaLiga rejected the refresh_token
 * (invalid_grant). The caller (typically a sync command) should surface this
 * as "please paste fresh tokens from your LaLiga Fantasy session" rather than
 * retry — retrying will not help until the user re-authenticates.
 */
class FantasyApiAuthenticationException extends FantasyApiException {}
