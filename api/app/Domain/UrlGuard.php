<?php

namespace App\Domain;

/**
 * PHP port of packages/core/src/url-guard.ts. Both must pass
 * packages/core/vectors/url-guard.json (AC-AST-001.2, AC-AST-001.3, MON-006).
 *
 * normalize() guards intake. isBlockedAddress() MUST also be called by the
 * probe worker on every resolved address and redirect hop (DNS rebinding).
 */
final class UrlGuard
{
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];
    private const MAX_INPUT = 2048;
    private const BLOCKED_SUFFIXES = ['.localhost', '.local', '.internal', '.intranet', '.lan', '.home.arpa', '.corp'];

    public const MESSAGES = [
        'empty' => 'Enter a website address, for example shop.example.com.',
        'too_long' => 'That address is too long.',
        'control_chars' => 'That address contains characters that are not allowed.',
        'bad_url' => "That doesn't look like a valid website address.",
        'scheme' => 'Only http:// and https:// websites can be monitored.',
        'credentials' => 'Remove the username or password from the address. Never put passwords in a URL.',
        'port' => 'Only standard web ports can be monitored (80, 443, 8080, 8443).',
        'private_address' => 'This address points to a private or reserved network and cannot be monitored.',
        'internal_name' => 'This looks like an internal network name and cannot be monitored.',
        'single_label' => 'Enter a full public domain name, for example example.com.',
    ];

    /**
     * @return array{ok: true, target: array{scheme: string, host: string, port: int, path: string, hostIsIp: bool}, droppedQuery: bool}
     *       | array{ok: false, code: string, message: string}
     */
    public static function normalize(?string $raw): array
    {
        // Not plain trim(): it strips NUL bytes, which must be rejected, not cleaned.
        $input = trim((string) $raw, " \t\n\r\v\f");
        if ($input === '') return self::fail('empty');
        if (mb_strlen($input) > self::MAX_INPUT) return self::fail('too_long');
        if (preg_match('/[\x00-\x1f\x7f\s\\\\]/u', $input)) return self::fail('control_chars');

        $hasScheme = preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)
            || preg_match('/^(javascript|data|mailto|file|about|blob|vbscript|tel):/i', $input);
        $url = $hasScheme ? $input : "https://{$input}";

        if (!preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m)) return self::fail('bad_url');
        $scheme = strtolower($m[1]);
        if ($scheme !== 'http' && $scheme !== 'https') return self::fail('scheme');

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) return self::fail('bad_url');
        if (isset($parts['user']) || isset($parts['pass'])) return self::fail('credentials');

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, self::ALLOWED_PORTS, true)) return self::fail('port');

        $host = rtrim(strtolower(rawurldecode($parts['host'])), '.');
        if ($host === '') return self::fail('bad_url');

        $hostIsIp = false;
        if (str_starts_with($host, '[')) {
            $v6 = substr($host, 1, -1);
            $bin = @inet_pton($v6);
            if ($bin === false || strlen($bin) !== 16) return self::fail('bad_url');
            if (self::isBlockedAddress($v6)) return self::fail('private_address');
            $host = '[' . inet_ntop($bin) . ']';
            $hostIsIp = true;
        } else {
            if (preg_match('/[^\x20-\x7e]/', $host)) {
                $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                if ($ascii === false) return self::fail('bad_url');
                $host = strtolower($ascii);
            }
            $v4 = self::whatwgIPv4($host);
            if ($v4 === false) return self::fail('bad_url');
            if ($v4 !== null) {
                $host = $v4;
                $hostIsIp = true;
                if (self::isBlockedAddress($host)) return self::fail('private_address');
            } else {
                if ($host === 'localhost') return self::fail('internal_name');
                foreach (self::BLOCKED_SUFFIXES as $s) {
                    if (str_ends_with($host, $s)) return self::fail('internal_name');
                }
                if (!str_contains($host, '.')) return self::fail('single_label');
                if (!preg_match('/^[a-z0-9.-]+$/', $host)) return self::fail('bad_url');
                foreach (explode('.', $host) as $label) {
                    if ($label === '' || strlen($label) > 63 || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                        return self::fail('bad_url');
                    }
                }
            }
        }

        return [
            'ok' => true,
            'droppedQuery' => isset($parts['query']) && $parts['query'] !== '',
            'target' => [
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'path' => ($parts['path'] ?? '') === '' ? '/' : $parts['path'],
                'hostIsIp' => $hostIsIp,
            ],
        ];
    }

    private static function fail(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'message' => self::MESSAGES[$code]];
    }

    /**
     * WHATWG URL IPv4 parsing: hosts whose last label is numeric are IPv4,
     * accepting hex (0x7f), octal (0177), short (127.1) and single-number forms.
     * Returns dotted-quad, null if not an IPv4 host, or false if invalid.
     */
    private static function whatwgIPv4(string $host): string|false|null
    {
        $parts = explode('.', $host);
        if (end($parts) === '' && count($parts) > 1) array_pop($parts);
        $last = end($parts);
        if (!preg_match('/^(0x[0-9a-f]*|\d+)$/i', (string) $last)) return null;
        if (count($parts) > 4) return false;

        $nums = [];
        foreach ($parts as $p) {
            if ($p === '') return false;
            if (preg_match('/^0x([0-9a-f]*)$/i', $p, $m)) {
                $n = $m[1] === '' ? 0 : hexdec($m[1]);
            } elseif (preg_match('/^0[0-7]+$/', $p)) {
                $n = octdec($p);
            } elseif (preg_match('/^\d+$/', $p)) {
                $n = (int) $p;
            } else {
                return false;
            }
            $nums[] = $n;
        }
        $lastNum = array_pop($nums);
        foreach ($nums as $n) {
            if ($n > 255) return false;
        }
        if ($lastNum >= 256 ** (4 - count($nums))) return false;
        $ip = $lastNum;
        foreach ($nums as $i => $n) {
            $ip += $n * 256 ** (3 - $i);
        }
        return long2ip((int) $ip);
    }

    private const V4_BLOCKED = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    private static function v4Blocked(int $ip): bool
    {
        foreach (self::V4_BLOCKED as [$net, $bits]) {
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if (($ip & $mask) === (ip2long($net) & $mask)) return true;
        }
        return false;
    }

    /** True when a resolved address must never be connected to. Unparseable → blocked (fail closed). */
    public static function isBlockedAddress(string $addr): bool
    {
        $a = trim($addr, '[]');
        if ($a === '' || str_contains($a, '%')) return true;
        if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::v4Blocked(ip2long($a));
        }
        $bin = @inet_pton($a);
        if ($bin === false || strlen($bin) !== 16) return true;
        $h = array_values(unpack('n8', $bin));
        $zero = fn (int $from, int $to) => array_sum(array_slice($h, $from, $to - $from)) === 0;
        $embedded = fn () => ($h[6] << 16) | $h[7];

        if ($zero(0, 8)) return true;                                     // ::
        if ($zero(0, 7) && $h[7] === 1) return true;                       // ::1
        if ($zero(0, 5) && $h[5] === 0xffff) return self::v4Blocked($embedded()); // ::ffff:a.b.c.d
        if ($zero(0, 6)) return true;                                      // ::a.b.c.d (deprecated)
        if ($h[0] === 0x64 && $h[1] === 0xff9b) return self::v4Blocked($embedded()); // NAT64
        if (($h[0] & 0xfe00) === 0xfc00) return true;                      // ULA
        if (($h[0] & 0xffc0) === 0xfe80) return true;                      // link-local
        if (($h[0] & 0xffc0) === 0xfec0) return true;                      // site-local
        if (($h[0] & 0xff00) === 0xff00) return true;                      // multicast
        if ($h[0] === 0x2001 && $h[1] === 0x0db8) return true;             // documentation
        if ($h[0] === 0x0100 && $zero(1, 4)) return true;                  // discard-only
        if ($h[0] === 0x2002) return self::v4Blocked(($h[1] << 16) | $h[2]); // 6to4
        return false;
    }
}
