<?php

namespace App\Connectors;

use App\Domain\UrlGuard;
use App\Support\Net\Resolver;

/**
 * HTTPS GET only, to an allowed port, connecting only to a vetted public
 * address (SSRF rules of MON-006 apply to provider hosts too: a panel host
 * that resolves to a private address is refused). No redirects, 2 MB cap.
 */
final class CurlConnectorHttp implements ConnectorHttp
{
    public const PORTS = [443, 2083, 2087];
    private const MAX_BYTES = 2_000_000;

    public function __construct(private Resolver $resolver, private ?string $proxy = null, private int $timeout = 20) {}

    public function get(string $url, array $headers): array
    {
        $u = parse_url($url);
        $host = strtolower($u['host'] ?? '');
        $port = (int) ($u['port'] ?? 443);
        if (($u['scheme'] ?? '') !== 'https' || $host === '' || !in_array($port, self::PORTS, true) || isset($u['user']) || isset($u['pass'])) {
            throw new ConnectorError('blocked', 'Only HTTPS on the provider\'s standard API port is allowed.');
        }
        $ips = $this->resolver->addresses($host);
        if ($ips === []) throw new ConnectorError('network', "The provider host {$host} does not resolve.");
        foreach ($ips as $ip) {
            if (UrlGuard::isBlockedAddress($ip)) throw new ConnectorError('blocked', "The provider host {$host} resolves to a private or reserved address.");
        }
        $pin = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_PROXY => $this->proxy ?? '',
            CURLOPT_NOPROXY => $this->proxy ? '' : '*',
            CURLOPT_USERAGENT => 'UpfixyConnector/1.0 (read-only)',
            CURLOPT_HTTPHEADER => array_merge(array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers), ['Accept: application/json']),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > self::MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (strlen($body) > self::MAX_BYTES) throw new ConnectorError('bad_response', 'The provider response was too large.');
        if ($ok === false) throw new ConnectorError('network', "Could not reach {$host}.");
        return ['status' => $status, 'body' => $body];
    }
}
