<?php

namespace App\Repairs;

use App\Domain\UrlGuard;

/**
 * The P0 operation allowlist (FRD §25.4) as code. Anything not explicitly
 * allowed is refused: MX, NS, SOA, CAA, TXT, wildcards, deletions, other
 * zones, names outside the website, private or malformed addresses.
 */
final class Policy
{
    public const TYPES = ['A', 'AAAA'];

    /** Is this live record one the executor may ever touch? */
    public static function refuseRecord(array $rec, string $zone, array $websiteHosts): ?string
    {
        $name = strtolower(rtrim($rec['name'], '.'));
        if (!in_array($rec['type'], self::TYPES, true)) return "only A and AAAA records can be changed automatically (this is {$rec['type']})";
        if (str_contains($name, '*')) return 'wildcard records are never changed automatically';
        if ($name !== $zone && !str_ends_with($name, '.' . $zone)) return 'the record is outside the connected zone';
        if (!in_array($name, array_map('strtolower', $websiteHosts), true)) return 'the record is not one of this website\'s monitored addresses';
        return null;
    }

    public static function refuseContent(string $type, string $proposed, string $current): ?string
    {
        // Exactly one canonical address: no whitespace, no leading zeros, lower-case IPv6 in shortest form.
        $p = $proposed;
        $flag = $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
        if (!filter_var($p, FILTER_VALIDATE_IP, $flag)) return "the new value must be a valid " . ($type === 'A' ? 'IPv4' : 'IPv6') . ' address';
        if ($p !== inet_ntop(inet_pton($p))) return 'the new value must be written in canonical form';
        // An AAAA record must hold a real IPv6 address, not an IPv4 address in IPv6 clothing (::ffff:a.b.c.d, ::a.b.c.d).
        if ($type === 'AAAA' && (str_starts_with($p, '::ffff:') || preg_match('/^::\d+\.\d+\.\d+\.\d+$/', $p))) {
            return 'an AAAA record must hold a native IPv6 address';
        }
        if (UrlGuard::isBlockedAddress($p)) return 'the new value is a private or reserved address';
        if ($p === strtolower($current)) return 'the record already has this value';
        return null;
    }

    /** Re-validate a stored plan at execution time (AC-FIX-003.3, AC-FIX-003.5). */
    public static function refusePlan(array $plan, object $connection, array $websiteHosts): ?string
    {
        if (($plan['operation'] ?? null) !== Executor::OPERATION || ($plan['version'] ?? null) !== 'repair-plan-v1') return 'this operation is not on the allowlist';
        if (($plan['zone_id'] ?? null) !== $connection->zone_id || ($plan['connection_id'] ?? null) !== $connection->id) return 'the change is outside the authorised zone';
        $rec = ['type' => $plan['record_type'] ?? '', 'name' => $plan['record_name'] ?? ''];
        if ($why = self::refuseRecord($rec, $connection->zone_name, $websiteHosts)) return $why;
        foreach (['before', 'after'] as $k) {
            if (!isset($plan[$k]['content'], $plan[$k]['ttl'], $plan[$k]['proxied'])) return 'the plan is incomplete';
        }
        if ($plan['before']['ttl'] !== $plan['after']['ttl'] || $plan['before']['proxied'] !== $plan['after']['proxied']) {
            return 'only the address may change; TTL and proxy settings must stay the same';
        }
        return self::refuseContent($plan['record_type'], $plan['after']['content'], $plan['before']['content']);
    }

    public static function same(array $rec, string $type, string $name, array $state): bool
    {
        return $rec['type'] === $type && strtolower(rtrim($rec['name'], '.')) === strtolower($name)
            && strtolower($rec['content']) === strtolower($state['content'])
            && (int) $rec['ttl'] === (int) $state['ttl'] && (bool) $rec['proxied'] === (bool) $state['proxied'];
    }

    /** What is kept in the audit trail: the record's public fields, never credentials. */
    public static function view(array $rec): array
    {
        return array_intersect_key($rec, array_flip(['type', 'name', 'content', 'ttl', 'proxied']));
    }
}
