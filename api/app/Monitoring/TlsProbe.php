<?php

namespace App\Monitoring;

use App\Monitoring\Tls\TlsError;
use App\Monitoring\Tls\TlsInspector;

/** MON-002: validity, hostname match, chain trust, days to expiry. */
final class TlsProbe
{
    public const WARN_DAYS = 14;
    public const CRITICAL_DAYS = 3;

    public function __construct(private TlsInspector $tls, private ?\Closure $clock = null) {}

    public function probe(string $host, int $port, array $addresses): Reading
    {
        if ($addresses === []) return Reading::unknown('tls', 'inconclusive', 'TLS_NOT_CHECKED', ['reason' => 'no validated address']);
        $ip = $addresses[0];
        foreach ($addresses as $a) if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $ip = $a; break; }
        try {
            $r = $this->tls->inspect($host, $ip, $port);
        } catch (TlsError $e) {
            return Reading::fail('tls', $e->issue, ['ip' => $ip]);
        }
        $now = $this->clock ? ($this->clock)() : time();
        $c = $r['cert'];
        if ($c === null) return Reading::fail('tls', 'TLS_HANDSHAKE_FAILED', ['ip' => $ip]);
        $days = (int) floor(($c['not_after'] - $now) / 86400);
        $detail = [
            'ip' => $ip, 'subject_cn' => $c['subject_cn'], 'issuer_cn' => $c['issuer_cn'], 'sans' => array_slice($c['sans'], 0, 20),
            'not_before' => gmdate('c', $c['not_before']), 'not_after' => gmdate('c', $c['not_after']), 'days_left' => $days,
            'sha256' => $c['sha256'], 'chain_length' => $r['chain_length'], 'protocol' => $r['protocol'], 'verified' => $r['verified'],
        ];
        // Explain a failed verification from the certificate itself, most specific first.
        if ($c['not_after'] <= $now) return Reading::fail('tls', 'TLS_EXPIRED', $detail);
        if ($c['not_before'] > $now) return Reading::fail('tls', 'TLS_NOT_YET_VALID', $detail);
        if (!self::matches($host, $c['sans'], $c['subject_cn'])) return Reading::fail('tls', 'TLS_HOSTNAME_MISMATCH', $detail);
        if (!$r['verified']) {
            return Reading::fail('tls', $c['self_signed'] ? 'TLS_SELF_SIGNED' : 'TLS_UNTRUSTED_CHAIN', $detail + ['verify_error' => substr((string) $r['verify_error'], 0, 160)]);
        }
        if ($days < self::CRITICAL_DAYS) return Reading::fail('tls', 'TLS_EXPIRING_CRITICAL', $detail);
        if ($days < self::WARN_DAYS) return Reading::warn('tls', 'TLS_EXPIRING', $detail);
        return Reading::ok('tls', 'TLS_OK', $detail);
    }

    /** RFC 6125: SANs win; CN only when there are no DNS SANs. One left-most wildcard label. */
    public static function matches(string $host, array $sans, ?string $cn): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $names = $sans !== [] ? $sans : ($cn ? [strtolower($cn)] : []);
        foreach ($names as $n) {
            if ($n === $host) return true;
            if (str_starts_with($n, '*.')) {
                $suffix = substr($n, 1);
                $label = substr($host, 0, strlen($host) - strlen($suffix));
                if (str_ends_with($host, $suffix) && $label !== '' && !str_contains($label, '.')) return true;
            }
        }
        return false;
    }
}
