<?php

namespace Tests\Fakes;

use App\Monitoring\Http\FetchError;
use App\Monitoring\Http\HttpFetcher;

/** $routes[url] = int status | [status, location] | 'HTTP_TIMEOUT' etc. Records every connection. */
final class FakeFetcher implements HttpFetcher
{
    public array $routes = [];
    public array $connections = [];
    /** @var array<string, array<int, mixed>> successive results per url */
    public array $sequence = [];

    public function fetch(string $url, string $host, int $port, string $pinnedIp): array
    {
        $this->connections[] = [$url, $pinnedIp];
        $v = isset($this->sequence[$url]) && $this->sequence[$url] !== [] ? array_shift($this->sequence[$url]) : ($this->routes[$url] ?? 404);
        if (is_string($v)) throw new FetchError($v);
        [$status, $location] = is_array($v) ? $v : [$v, null];
        return ['status' => $status, 'location' => $location, 'content_type' => 'text/html', 'bytes' => 1200,
            'ms_total' => 120, 'ms_connect' => 20, 'ms_ttfb' => 90];
    }
}
