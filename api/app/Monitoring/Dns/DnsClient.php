<?php

namespace App\Monitoring\Dns;

/** Asks ONE specific resolver ONE question. Implementations never fall back to the OS resolver. */
interface DnsClient
{
    /**
     * @return array{rcode:string, ad:bool, answers:array<int, array{type:string, ttl:int, data:mixed}>, ms:int}
     * @throws DnsTimeout when the resolver did not answer in time
     * @throws \RuntimeException on any other transport or protocol error
     */
    public function query(string $resolver, string $name, string $type, bool $checkingDisabled = false): array;
}
