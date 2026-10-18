<?php

namespace App\Support\Net;

interface Resolver
{
    /** @return string[] IPv4/IPv6 addresses for $host (an IP literal resolves to itself). */
    public function addresses(string $host): array;

    /** @return string[] TXT record strings at $name. */
    public function txt(string $name): array;
}
