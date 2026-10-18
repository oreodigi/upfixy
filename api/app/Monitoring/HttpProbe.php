<?php

namespace App\Monitoring;

use App\Domain\UrlGuard;
use App\Monitoring\Http\FetchError;
use App\Monitoring\Http\HttpFetcher;

/**
 * MON-001: status, redirect chain (max 10), timing. Redirects are followed
 * here, not by curl, so every hop's host is resolved, checked against the
 * blocklist and pinned before connecting (AC-MON-001.3, AC-MON-006.1).
 * Only safe facts are kept: no bodies, no query strings, no cookies.
 */
final class HttpProbe
{
    public const MAX_REDIRECTS = 10;
    public const SLOW_MS = 10000;
    private const PORTS = [80, 443, 8080, 8443];

    public function __construct(private HttpFetcher $http, private DnsProbe $dns) {}

    /** @param string[] $addresses validated addresses of the target host from this cycle's DNS check */
    public function probe(string $scheme, string $host, int $port, string $path, array $addresses): Reading
    {
        $url = self::build($scheme, $host, $port, $path);
        $chain = [];
        $startScheme = $scheme;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if ($hop > 0) {
                [$code, $addresses] = $this->dns->resolve($host);
                if ($code === 'DNS_PRIVATE_ADDRESS') {
                    $chain[] = ['url' => self::redact($url), 'blocked' => 'private address'];
                    return new Reading('http', 'warning', 'probe_blocked', 'HTTP_REDIRECT_BLOCKED', ['chain' => $chain]);
                }
                if ($code !== 'DNS_OK') {
                    return Reading::fail('http', 'HTTP_REDIRECT_UNRESOLVABLE', ['chain' => $chain, 'dns' => $code]);
                }
            }
            foreach ($addresses as $ip) {
                if (UrlGuard::isBlockedAddress($ip)) { // defence in depth: callers already filtered
                    return new Reading('http', 'unknown', 'probe_blocked', 'DNS_PRIVATE_ADDRESS', ['chain' => $chain]);
                }
            }
            if ($addresses === []) {
                return Reading::unknown('http', 'inconclusive', 'HTTP_NOT_CHECKED', ['reason' => 'no validated address']);
            }
            $ip = self::prefer($addresses);
            try {
                $r = $this->http->fetch($url, $host, $port, $ip);
            } catch (FetchError $e) {
                $chain[] = ['url' => self::redact($url), 'ip' => $ip, 'error' => $e->issue];
                return Reading::fail('http', $e->issue, ['chain' => $chain]);
            }
            $chain[] = ['url' => self::redact($url), 'ip' => $ip, 'status' => $r['status'], 'ms' => $r['ms_total']];

            if ($r['status'] >= 300 && $r['status'] < 400 && $r['location'] !== null) {
                $next = self::resolveLocation($url, $r['location']);
                if ($next === null) {
                    return Reading::warn('http', 'HTTP_REDIRECT_INVALID', ['chain' => $chain]);
                }
                [$scheme, $host, $port, $path] = $next;
                if (!in_array($port, self::PORTS, true)) {
                    $chain[] = ['url' => self::redact(self::build($scheme, $host, $port, $path)), 'blocked' => 'port not allowed'];
                    return new Reading('http', 'warning', 'probe_blocked', 'HTTP_REDIRECT_BLOCKED', ['chain' => $chain]);
                }
                $url = self::build($scheme, $host, $port, $path);
                continue;
            }
            return $this->classify($r, $chain, $startScheme, $scheme);
        }
        return Reading::warn('http', 'HTTP_TOO_MANY_REDIRECTS', ['chain' => $chain]);
    }

    private function classify(array $r, array $chain, string $from, string $to): Reading
    {
        $s = $r['status'];
        $detail = ['chain' => $chain, 'status' => $s, 'ms_total' => array_sum(array_column($chain, 'ms')),
            'ms_ttfb' => $r['ms_ttfb'], 'ms_connect' => $r['ms_connect'], 'bytes' => $r['bytes'], 'content_type' => $r['content_type']];
        if ($s >= 500) {
            $code = in_array($s, [500, 502, 503, 504], true) ? "HTTP_{$s}" : 'HTTP_5XX';
            return Reading::fail('http', $code, $detail);
        }
        if ($s === 404 || $s === 410) return Reading::fail('http', "HTTP_{$s}", $detail);
        if ($s === 401 || $s === 403) return Reading::warn('http', "HTTP_{$s}", $detail);
        if ($s === 429) return Reading::unknown('http', 'probe_blocked', 'HTTP_429', $detail);
        if ($s >= 400) return Reading::warn('http', 'HTTP_4XX', $detail);
        if ($s >= 300) return Reading::warn('http', 'HTTP_REDIRECT_INVALID', $detail); // 3xx without Location
        if ($s < 200) return Reading::fail('http', 'HTTP_REQUEST_FAILED', $detail);
        if ($from === 'https' && $to === 'http') return Reading::warn('http', 'HTTP_HTTPS_DOWNGRADE', $detail);
        if ($detail['ms_total'] > self::SLOW_MS) return Reading::warn('http', 'HTTP_SLOW', $detail);
        return Reading::ok('http', 'HTTP_OK', $detail);
    }

    public static function build(string $scheme, string $host, int $port, string $path): string
    {
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $h = str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host;
        return "{$scheme}://{$h}" . ($default ? '' : ":{$port}") . $path;
    }

    /** Query strings and fragments can carry tokens: never stored (NFR-21). */
    public static function redact(string $url): string
    {
        $q = strpos($url, '?');
        $f = strpos($url, '#');
        $cut = min($q === false ? PHP_INT_MAX : $q, $f === false ? PHP_INT_MAX : $f);
        return $cut === PHP_INT_MAX ? $url : substr($url, 0, $cut) . '?…';
    }

    /** @return ?array{0:string,1:string,2:int,3:string} scheme, host, port, path-with-query */
    public static function resolveLocation(string $base, string $location): ?array
    {
        if (strlen($location) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $location)) return null;
        $b = parse_url($base);
        if (str_starts_with($location, '//')) $location = $b['scheme'] . ':' . $location;
        elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            $path = str_starts_with($location, '/') ? $location
                : preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/') . $location;
            $location = self::build($b['scheme'], $b['host'], $b['port'] ?? ($b['scheme'] === 'https' ? 443 : 80), $path);
        }
        $u = parse_url($location);
        if (!$u || !isset($u['scheme'], $u['host'])) return null;
        $scheme = strtolower($u['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || isset($u['user']) || isset($u['pass'])) return null;
        $host = strtolower(trim($u['host'], '[]'));
        $port = $u['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        return [$scheme, $host, (int) $port, $path === '' ? '/' : $path];
    }

    /** IPv4 first: many probe hosts have no IPv6 route, which would read as a site failure. */
    private static function prefer(array $ips): string
    {
        foreach ($ips as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $ip;
        return $ips[0];
    }
}
