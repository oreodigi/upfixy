<?php

namespace App\Connectors;

use RuntimeException;

/**
 * A provider or transport failure. $kind is one of:
 * unauthorized, forbidden, rate_limited, server_error, network, blocked, bad_response.
 * Messages are written for the customer and never contain tokens or raw provider bodies.
 */
class ConnectorError extends RuntimeException
{
    public const REJECTING = ['unauthorized', 'forbidden', 'blocked'];

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
