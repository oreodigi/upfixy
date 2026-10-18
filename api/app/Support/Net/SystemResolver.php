<?php

namespace App\Support\Net;

/** OS resolver. A multi-resolver implementation replaces this in M3. */
final class SystemResolver implements Resolver
{
    public function addresses(string $host): array
    {
        $h = trim($host, '[]');
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            return [$h];
        }
        $out = [];
        foreach (@dns_get_record($h, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (isset($r['ip'])) $out[] = $r['ip'];
            if (isset($r['ipv6'])) $out[] = $r['ipv6'];
        }
        return array_values(array_unique($out));
    }

    public function txt(string $name): array
    {
        $out = [];
        foreach (@dns_get_record($name, DNS_TXT) ?: [] as $r) {
            // Long TXT values arrive split into 255-byte entries.
            $out[] = isset($r['entries']) ? implode('', $r['entries']) : ($r['txt'] ?? '');
        }
        return $out;
    }
}
