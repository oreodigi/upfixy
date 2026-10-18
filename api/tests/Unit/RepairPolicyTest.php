<?php

namespace Tests\Unit;

use App\Repairs\Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Task 4 validation of the P0 allowlist (FIX-003, §25.4): edge inputs the executor must refuse. */
class RepairPolicyTest extends TestCase
{
    public static function refused(): array
    {
        return [
            'private v4' => ['A', '10.0.0.5'], 'loopback' => ['A', '127.0.0.1'], 'metadata' => ['A', '169.254.169.254'], 'cgnat' => ['A', '100.64.0.1'],
            'zero' => ['A', '0.0.0.0'], 'broadcast' => ['A', '255.255.255.255'], 'multicast' => ['A', '224.0.0.1'], 'test-net' => ['A', '192.0.2.1'],
            'leading zero' => ['A', '093.184.216.35'], 'leading space' => ['A', ' 93.184.216.35'], 'trailing newline' => ['A', "93.184.216.35\n"],
            'v6 in A' => ['A', '2606:2800:220:1::1'], 'v4 in AAAA' => ['AAAA', '93.184.216.35'],
            'mapped private' => ['AAAA', '::ffff:10.0.0.1'], 'mapped public' => ['AAAA', '::ffff:93.184.216.34'], 'compat' => ['AAAA', '::93.184.216.34'],
            'link-local' => ['AAAA', 'fe80::1'], 'ula' => ['AAAA', 'fc00::1'], 'doc' => ['AAAA', '2001:db8::1'], 'nat64' => ['AAAA', '64:ff9b::a00:1'],
            'unspecified' => ['AAAA', '::'], '6to4 private' => ['AAAA', '2002:a00:1::1'],
            'upper-case v6' => ['AAAA', '2606:2800:220:1:248:1893:25C8:1946'], 'long-form v6' => ['AAAA', '2606:2800:0220:0001:0248:1893:25c8:1946'],
            'zone id' => ['AAAA', 'fe80::1%eth0'], 'cidr' => ['A', '93.184.216.0/24'], 'hostname' => ['A', 'example.com'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refused_values(string $type, string $value): void
    {
        $this->assertNotNull(Policy::refuseContent($type, $value, '93.184.216.1'), "{$type} {$value} was allowed");
    }

    public function test_allowed_values(): void
    {
        $this->assertNull(Policy::refuseContent('A', '93.184.216.35', '93.184.216.34'));
        $this->assertNull(Policy::refuseContent('AAAA', '2606:2800:220:1:248:1893:25c8:1946', '2606:2800:220:1::1'));
        $this->assertNotNull(Policy::refuseContent('A', '93.184.216.34', '93.184.216.34'), 'no-op change allowed');
    }

    public function test_records_outside_scope(): void
    {
        $hosts = ['shop.mitra.example'];
        $this->assertNull(Policy::refuseRecord(['type' => 'A', 'name' => 'SHOP.MITRA.EXAMPLE.'], 'mitra.example', $hosts));
        foreach ([['A', '*.mitra.example'], ['CNAME', 'shop.mitra.example'], ['MX', 'shop.mitra.example'], ['TXT', 'shop.mitra.example'],
                     ['NS', 'mitra.example'], ['CAA', 'mitra.example'], ['A', 'mitra.example.evil.com'], ['A', 'evilmitra.example'],
                     ['A', 'admin.mitra.example'], ['A', 'mitra.example']] as [$t, $n]) {
            $this->assertNotNull(Policy::refuseRecord(['type' => $t, 'name' => $n], 'mitra.example', $hosts), "{$t} {$n}");
        }
    }

    public function test_plans_that_change_ttl_or_proxy_or_name_another_operation_are_refused(): void
    {
        $conn = (object) ['id' => 'c1', 'zone_id' => str_repeat('a', 32), 'zone_name' => 'mitra.example'];
        $plan = ['operation' => 'cloudflare.dns.update_address_record', 'version' => 'repair-plan-v1', 'connection_id' => 'c1', 'zone_id' => str_repeat('a', 32),
            'record_type' => 'A', 'record_name' => 'shop.mitra.example',
            'before' => ['content' => '93.184.216.34', 'ttl' => 300, 'proxied' => false], 'after' => ['content' => '93.184.216.35', 'ttl' => 300, 'proxied' => false]];
        $hosts = ['shop.mitra.example'];
        $this->assertNull(Policy::refusePlan($plan, $conn, $hosts));
        foreach ([
            ['after' => ['content' => '93.184.216.35', 'ttl' => 60, 'proxied' => false]],
            ['after' => ['content' => '93.184.216.35', 'ttl' => 300, 'proxied' => true]],
            ['operation' => 'cloudflare.dns.delete_record'], ['version' => 'repair-plan-v2'], ['zone_id' => str_repeat('b', 32)], ['connection_id' => 'c2'],
            ['record_type' => 'MX'], ['record_name' => 'other.mitra.example'],
        ] as $bad) {
            $this->assertNotNull(Policy::refusePlan(array_replace($plan, $bad), $conn, $hosts), json_encode($bad));
        }
        $p = $plan; unset($p['after']['ttl']);
        $this->assertNotNull(Policy::refusePlan($p, $conn, $hosts));
    }
}
