<?php

namespace App\Monitoring\Http;

/** One HTTP(S) request to ONE pre-validated address. Never follows redirects, never sends cookies. */
interface HttpFetcher
{
    /**
     * @return array{status:int, location:?string, content_type:?string, bytes:int, ms_total:int, ms_connect:int, ms_ttfb:int}
     * @throws FetchError
     */
    public function fetch(string $url, string $host, int $port, string $pinnedIp): array;
}
