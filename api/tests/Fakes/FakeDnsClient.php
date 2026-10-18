<?php

namespace Tests\Fakes;

use App\Monitoring\Dns\DnsClient;
use App\Monitoring\Dns\DnsTimeout;

/**
 * Scripted resolvers. $zone[resolver][name][type] = rcode string or list of
 * answers; 'TIMEOUT' simulates silence. '*' as resolver applies to all.
 * $sequence lets a name change between calls (DNS rebinding).
 */
final class FakeDnsClient implements DnsClient
{
    public array $zone = [];
    /** @var array<string, array<int, mixed>> name|type => successive answers */
    public array $sequence = [];
    public array $calls = [];

    public function set(string $name, array $ips, string $resolver = '*'): self
    {
        $a = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        $aaaa = array_values(array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)));
        $this->zone[$resolver][$name]['A'] = array_map(fn ($ip) => ['type' => 'A', 'ttl' => 60, 'data' => $ip], $a);
        $this->zone[$resolver][$name]['AAAA'] = array_map(fn ($ip) => ['type' => 'AAAA', 'ttl' => 60, 'data' => $ip], $aaaa);
        return $this;
    }

    public function rcode(string $name, string $rcode, string $resolver = '*'): self
    {
        $this->zone[$resolver][$name]['A'] = $rcode;
        $this->zone[$resolver][$name]['AAAA'] = $rcode;
        return $this;
    }

    public function query(string $resolver, string $name, string $type, bool $checkingDisabled = false): array
    {
        $this->calls[] = [$resolver, $name, $type, $checkingDisabled];
        $key = "{$name}|{$type}";
        if ($checkingDisabled && isset($this->zone['cd'][$name][$type])) {
            $v = $this->zone['cd'][$name][$type];
        } elseif (isset($this->sequence[$key]) && $this->sequence[$key] !== []) {
            $v = array_shift($this->sequence[$key]);
        } else {
            $v = $this->zone[$resolver][$name][$type] ?? $this->zone['*'][$name][$type] ?? null;
        }
        if ($v === 'TIMEOUT') throw new DnsTimeout('silent');
        if (is_string($v)) return ['rcode' => $v, 'ad' => false, 'answers' => [], 'ms' => 5];
        if ($v === null) return ['rcode' => 'NOERROR', 'ad' => false, 'answers' => [], 'ms' => 5];
        return ['rcode' => 'NOERROR', 'ad' => false, 'answers' => $v, 'ms' => 5];
    }
}
