<?php

namespace App\Monitoring\Tls;

/**
 * Two handshakes at most: one verifying (the visitor's view), and only if
 * that fails, one without verification to read the certificate and explain
 * WHY it failed. The second connection never carries application data.
 */
final class StreamTlsInspector implements TlsInspector
{
    public function __construct(private float $timeout = 8.0, private ?string $caFile = null) {}

    public function inspect(string $host, string $ip, int $port): array
    {
        $start = hrtime(true);
        [$ok, $cert, $chain, $proto, $err] = $this->handshake($host, $ip, $port, true);
        if (!$ok) {
            [$ok2, $cert, $chain, $proto, $err2] = $this->handshake($host, $ip, $port, false);
            if (!$ok2) throw new TlsError(self::classify($err2 ?? $err), $err2 ?? $err ?? '');
        }
        return [
            'verified' => $ok, 'verify_error' => $ok ? null : $err,
            'cert' => $cert ? self::parse($cert) : null, 'chain_length' => $chain, 'protocol' => $proto,
            'ms' => (int) ((hrtime(true) - $start) / 1e6),
        ];
    }

    private function handshake(string $host, string $ip, int $port, bool $verify): array
    {
        $ssl = [
            'peer_name' => $host, 'SNI_enabled' => true, 'capture_peer_cert' => true, 'capture_peer_cert_chain' => true,
            'verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify,
        ];
        if ($this->caFile) $ssl['cafile'] = $this->caFile;
        $ctx = stream_context_create(['ssl' => $ssl]);
        $addr = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $errno = 0; $errstr = '';
        $warnings = [];
        set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
        try {
            $s = stream_socket_client("ssl://{$addr}:{$port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        } finally {
            restore_error_handler();
        }
        if (!$s) {
            $detail = trim($errstr . ' ' . implode(' ', $warnings));
            return [false, null, 0, null, $detail !== '' ? $detail : "errno {$errno}"];
        }
        $p = stream_context_get_params($s)['options']['ssl'] ?? [];
        $meta = stream_get_meta_data($s);
        fclose($s);
        return [true, $p['peer_certificate'] ?? null, count($p['peer_certificate_chain'] ?? []), $meta['crypto']['protocol'] ?? null, null];
    }

    public static function classify(?string $error): string
    {
        $e = strtolower($error ?? '');
        return match (true) {
            str_contains($e, 'timed out') || str_contains($e, 'timeout') => 'TLS_TIMEOUT',
            str_contains($e, 'refused') => 'TLS_CONNECTION_REFUSED',
            default => 'TLS_HANDSHAKE_FAILED',
        };
    }

    public static function parse($cert): array
    {
        $x = openssl_x509_parse($cert, false) ?: [];
        $sans = [];
        foreach (explode(',', $x['extensions']['subjectAltName'] ?? '') as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) $sans[] = strtolower(substr($part, 4));
        }
        $subject = $x['subject']['commonName'] ?? null;
        $issuer = $x['issuer']['commonName'] ?? null;
        openssl_x509_export($cert, $pem);
        return [
            'subject_cn' => is_array($subject) ? $subject[0] : $subject,
            'issuer_cn' => is_array($issuer) ? $issuer[0] : $issuer,
            'sans' => array_slice($sans, 0, 50),
            'not_before' => (int) ($x['validFrom_time_t'] ?? 0),
            'not_after' => (int) ($x['validTo_time_t'] ?? 0),
            'sha256' => openssl_x509_fingerprint($cert, 'sha256') ?: '',
            'self_signed' => ($x['subject'] ?? null) === ($x['issuer'] ?? null),
        ];
    }
}
