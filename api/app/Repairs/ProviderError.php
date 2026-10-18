<?php

namespace App\Repairs;

/**
 * kind: not_found | unauthorized | rate_limited | rejected | server_error | unknown_outcome
 * unknown_outcome means a write may or may not have happened: reconcile before anything else.
 */
final class ProviderError extends \RuntimeException
{
    public function __construct(public readonly string $kind, public readonly ?string $ref = null, string $message = '')
    {
        parent::__construct($message ?: $kind);
    }
}
