<?php

namespace App\Monitoring\Http;

/** A failed request, classified into an issue-library code (HTTP_TIMEOUT, HTTP_TLS_ERROR, ...). */
final class FetchError extends \RuntimeException
{
    public function __construct(public readonly string $issue, string $message = '')
    {
        parent::__construct($message ?: $issue);
    }
}
