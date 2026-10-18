<?php

namespace App\Monitoring\Tls;

interface TlsInspector
{
    /**
     * Handshake with $ip:$port presenting SNI $host.
     * @return array{verified:bool, verify_error:?string, cert:?array{subject_cn:?string, issuer_cn:?string, sans:string[],
     *               not_before:int, not_after:int, sha256:string, self_signed:bool}, chain_length:int, protocol:?string, ms:int}
     * @throws TlsError when no handshake completed at all (timeout, refused, handshake failure)
     */
    public function inspect(string $host, string $ip, int $port): array;
}
