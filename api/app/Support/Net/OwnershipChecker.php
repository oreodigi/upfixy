<?php

namespace App\Support\Net;

use App\Domain\UrlGuard;
use Throwable;

/**
 * Looks at the live host for an ownership token (D-07).
 * Returns what was actually found; the database decides whether it matches.
 * Every address is checked against the SSRF blocklist before any connection,
 * the connection is pinned to a checked address, and redirects are not followed.
 */
final class OwnershipChecker
{
    public const MAX_BYTES = 1024;
    public const TIMEOUT = 5;

    public function __construct(private Resolver $resolver, private HttpTransport $http) {}

    /** @return array{found: ?string, detail: string} */
    public function check(object $claim): array
    {
        return $claim->method === 'dns_txt' ? $this->dns($claim) : $this->html($claim);
    }

    public static function txtName(string $host): string
    {
        return "_upfixy-challenge.{$host}";
    }

    public static function filePath(string $claimId): string
    {
        return "/.well-known/upfixy-verify-{$claimId}.txt";
    }

    private function dns(object $c): array
    {
        $name = self::txtName($c->host);
        try {
            $values = $this->resolver->txt($name);
        } catch (Throwable) {
            return ['found' => null, 'detail' => "DNS lookup for {$name} failed. Try again in a few minutes."];
        }
        if ($values === []) {
            return ['found' => null, 'detail' => "No TXT record found at {$name} yet. DNS changes can take up to an hour."];
        }
        $expected = 'upfixy-verify=' . $c->nonce;
        foreach ($values as $v) {
            if (trim($v, " \"\t") === $expected) {
                return ['found' => $expected, 'detail' => "TXT record at {$name} matched."];
            }
        }
        return ['found' => null, 'detail' => "A TXT record exists at {$name}, but its value doesn't match."];
    }

    private function html(object $c): array
    {
        $host = trim($c->host, '[]');
        try {
            $ips = $this->resolver->addresses($c->host);
        } catch (Throwable) {
            $ips = [];
        }
        if ($ips === []) {
            return ['found' => null, 'detail' => "{$c->host} does not resolve to an address."];
        }
        foreach ($ips as $ip) {
            if (UrlGuard::isBlockedAddress($ip)) {
                return ['found' => null, 'detail' => "{$c->host} resolves to a private or reserved address, so it can't be checked."];
            }
        }
        $port = (int) $c->port;
        $default = ($c->scheme === 'https' && $port === 443) || ($c->scheme === 'http' && $port === 80);
        $url = $c->scheme . '://' . $c->host . ($default ? '' : ":{$port}") . self::filePath($c->id);
        try {
            $r = $this->http->get($url, $host, $port, $ips[0], self::MAX_BYTES, self::TIMEOUT);
        } catch (Throwable $e) {
            return ['found' => null, 'detail' => "Couldn't reach {$url}."];
        }
        if ($r['status'] !== 200) {
            return ['found' => null, 'detail' => "{$url} returned HTTP {$r['status']}. Redirects are not followed."];
        }
        $first = trim(strtok($r['body'], "\r\n") ?: '');
        $expected = 'upfixy-verify=' . $c->nonce;
        return $first === $expected
            ? ['found' => $expected, 'detail' => "Verification file at {$url} matched."]
            : ['found' => null, 'detail' => "The file at {$url} doesn't contain the expected token."];
    }
}
