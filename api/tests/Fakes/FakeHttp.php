<?php

namespace Tests\Fakes;

use App\Support\Net\HttpTransport;

final class FakeHttp implements HttpTransport
{
    /** @var array<string, array{status: int, body: string}> url => response */
    public array $responses = [];
    /** @var array<int, array{url: string, ip: string}> */
    public array $calls = [];

    public function get(string $url, string $host, int $port, string $pinnedIp, int $maxBytes, int $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'ip' => $pinnedIp];
        return $this->responses[$url] ?? ['status' => 404, 'body' => ''];
    }
}
