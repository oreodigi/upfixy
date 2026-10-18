<?php

namespace Tests\Fakes;

use App\Connectors\ConnectorError;
use App\Connectors\ConnectorHttp;

/** Scripted provider: exact URL → [status, json] or a ConnectorError kind. Records every call. */
final class FakeConnectorHttp implements ConnectorHttp
{
    /** @var array<string, array{0: int, 1: mixed}|string> */
    public array $routes = [];
    /** @var list<array{url: string, headers: array}> */
    public array $calls = [];

    public function get(string $url, array $headers): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers];
        $r = $this->routes[$url] ?? [404, ['success' => false]];
        if (is_string($r)) throw new ConnectorError($r, "simulated {$r}");
        return ['status' => $r[0], 'body' => is_string($r[1]) ? $r[1] : json_encode($r[1])];
    }

    public function urls(): array
    {
        return array_column($this->calls, 'url');
    }
}
