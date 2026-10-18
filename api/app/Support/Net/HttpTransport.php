<?php

namespace App\Support\Net;

interface HttpTransport
{
    /**
     * GET $url, connecting ONLY to $pinnedIp. No redirects are followed.
     * @return array{status: int, body: string}
     * @throws \RuntimeException on network failure
     */
    public function get(string $url, string $host, int $port, string $pinnedIp, int $maxBytes, int $timeoutSeconds): array;
}
