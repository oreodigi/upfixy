<?php

namespace App\Connectors;

/** Shared response handling: provider status → ConnectorError, body → array. */
final class Json
{
    public static function decode(array $r, string $provider): array
    {
        $s = $r['status'];
        if ($s === 401) throw new ConnectorError('unauthorized', "{$provider} did not accept the credentials.");
        if ($s === 403) throw new ConnectorError('forbidden', "{$provider} refused this request for the credentials given.");
        if ($s === 429) throw new ConnectorError('rate_limited', "{$provider} is rate limiting requests; Upfixy will retry later.");
        if ($s >= 500) throw new ConnectorError('server_error', "{$provider} returned a server error; Upfixy will retry later.");
        if ($s < 200 || $s >= 300) throw new ConnectorError('bad_response', "{$provider} returned an unexpected response (HTTP {$s}).");
        $j = json_decode($r['body'], true);
        if (!is_array($j)) throw new ConnectorError('bad_response', "{$provider} returned something that is not JSON.");
        return $j;
    }

    /** Lower-case hostname or null when the provider sent something that is not one. */
    public static function host(mixed $v): ?string
    {
        $h = strtolower(rtrim(trim((string) $v), '.'));
        return preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h) && strlen($h) <= 253 ? $h : null;
    }

    /** Short printable text from a provider (labels, issuers). */
    public static function text(mixed $v, int $max = 80): ?string
    {
        if ($v === null || $v === '') return null;
        return substr(preg_replace('/[^\x20-\x7E]/', '', (string) $v), 0, $max);
    }
}
