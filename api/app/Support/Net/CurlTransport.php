<?php

namespace App\Support\Net;

use RuntimeException;

final class CurlTransport implements HttpTransport
{
    public function __construct(private ?string $proxy = null) {}

    public function get(string $url, string $host, int $port, string $pinnedIp, int $maxBytes, int $timeoutSeconds): array
    {
        $body = '';
        $ch = curl_init($url);
        $ip = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],       // connect only to the validated address
            CURLOPT_FOLLOWLOCATION => false,                     // redirects are never followed
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_PROXY => $this->proxy ?? '',                 // ignore *_proxy environment variables
            CURLOPT_NOPROXY => $this->proxy ? '' : '*',
            CURLOPT_USERAGENT => 'UpfixyVerifier/1.0 (+ownership check)',
            CURLOPT_HTTPHEADER => ['Accept: text/plain'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, $maxBytes) {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk); // abort oversized bodies
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false && strlen($body) <= $maxBytes) {
            throw new RuntimeException($err !== '' ? $err : 'request failed');
        }
        return ['status' => $status, 'body' => substr($body, 0, $maxBytes)];
    }
}
