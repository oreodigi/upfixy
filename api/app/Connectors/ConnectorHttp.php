<?php

namespace App\Connectors;

/**
 * The ONLY way connectors reach a provider. It has no method, body or verb
 * parameter: it can issue HTTPS GETs and nothing else, so no adapter can send
 * a write even if the provider token would allow one (FRD §7, AC-INT-003.1).
 */
interface ConnectorHttp
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws ConnectorError
     */
    public function get(string $url, array $headers): array;
}
