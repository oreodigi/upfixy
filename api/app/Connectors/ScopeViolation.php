<?php

namespace App\Connectors;

/** The token's reach could not be proven to be what the connector allows (D-04, §16). */
final class ScopeViolation extends ConnectorError
{
    public function __construct(string $message)
    {
        parent::__construct('scope', $message);
    }
}
