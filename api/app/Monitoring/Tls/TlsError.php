<?php

namespace App\Monitoring\Tls;

final class TlsError extends \RuntimeException
{
    public function __construct(public readonly string $issue, string $message = '')
    {
        parent::__construct($message ?: $issue);
    }
}
