<?php

namespace App\Monitoring\Http;

/**
 * Budgets (§17.2): connect and total time, response bytes, no decompression
 * (identity encoding only, so no decompression bombs), HTTP(S) only, the
 * connection pinned to the validated IP, environment proxies ignored.
 */
final class CurlFetcher implements HttpFetcher
{
    public function __construct(
        private int $connectTimeout = 5,
        private int $totalTimeout = 15,
        private int $maxBytes = 65536,
        private string $userAgent = 'UpfixyMonitor/1.0',
        private ?string $proxy = null,
    ) {}

    public function fetch(string $url, string $host, int $port, string $pinnedIp): array
    {
        $bytes = 0;
        $location = null;
        $ctype = null;
        $ch = curl_init($url);
        $ip = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->totalTimeout,
            CURLOPT_PROXY => $this->proxy ?? '',
            CURLOPT_NOPROXY => $this->proxy ? '' : '*',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.8', 'Accept-Encoding: identity'],
            // No cookie engine is enabled, so cookies are never stored or sent (§17.1).
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$location, &$ctype) {
                if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                if (stripos($line, 'content-type:') === 0) $ctype = substr(trim(substr($line, 13)), 0, 100);
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$bytes) {
                $bytes += strlen($chunk);
                return $bytes > $this->maxBytes ? 0 : strlen($chunk); // body is measured, never kept
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        $capped = $errno === CURLE_WRITE_ERROR && $bytes > $this->maxBytes;
        if ($ok === false && !$capped) {
            throw new FetchError(self::classify($errno), curl_strerror($errno));
        }
        return [
            'status' => (int) $info['http_code'],
            'location' => $location,
            'content_type' => $ctype,
            'bytes' => min($bytes, $this->maxBytes),
            'ms_total' => (int) round($info['total_time'] * 1000),
            'ms_connect' => (int) round($info['connect_time'] * 1000),
            'ms_ttfb' => (int) round($info['starttransfer_time'] * 1000),
        ];
    }

    public static function classify(int $errno): string
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => 'HTTP_TIMEOUT',
            CURLE_COULDNT_CONNECT => 'HTTP_CONNECTION_REFUSED',
            CURLE_GOT_NOTHING => 'HTTP_EMPTY_RESPONSE',
            CURLE_RECV_ERROR, CURLE_SEND_ERROR => 'HTTP_CONNECTION_RESET',
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER,
            CURLE_SSL_CACERT_BADFILE, 60 => 'HTTP_TLS_ERROR',
            CURLE_TOO_MANY_REDIRECTS => 'HTTP_TOO_MANY_REDIRECTS',
            default => 'HTTP_REQUEST_FAILED',
        };
    }
}
