<?php

namespace App\Domain;

use DateTimeInterface;

/**
 * PHP port of packages/core/src/health.ts (FRD §15.2, D-17). The server is the
 * authority for lanes; both implementations pass packages/core/vectors/health.json.
 */
final class Health
{
    public const REQUIRED = ['http', 'tls', 'dns'];
    public const STALE_MULTIPLIER = 3;

    public static function freshness(?DateTimeInterface $observedAt, int $intervalSeconds, DateTimeInterface $now): string
    {
        if ($observedAt === null) return 'never_checked';
        $age = $now->getTimestamp() - $observedAt->getTimestamp();
        return $age <= self::STALE_MULTIPLIER * $intervalSeconds ? 'fresh' : 'stale';
    }

    /**
     * @param array<int, array{kind: string, severity: string, freshness: string}> $readings latest per kind
     * @return array{lane: string, reasons: string[]}
     */
    public static function site(bool $monitorActive, array $readings, array $required = self::REQUIRED): array
    {
        $fresh = array_filter($readings, fn ($r) => $r['freshness'] === 'fresh');

        $critical = array_values(array_filter($fresh, fn ($r) => $r['severity'] === 'critical'));
        if ($critical) return ['lane' => 'critical', 'reasons' => array_column($critical, 'kind')];

        $warning = array_values(array_filter($fresh, fn ($r) => $r['severity'] === 'warning'));
        if ($warning) return ['lane' => 'warning', 'reasons' => array_column($warning, 'kind')];

        $neverChecked = array_filter($readings, fn ($r) => $r['freshness'] !== 'never_checked') === [];
        if (!$monitorActive && $neverChecked) return ['lane' => 'needs_setup', 'reasons' => []];

        $byKind = array_column($readings, null, 'kind');
        $notOk = array_values(array_filter($required, function ($k) use ($byKind) {
            $r = $byKind[$k] ?? null;
            return $r === null || $r['freshness'] !== 'fresh' || !in_array($r['severity'], ['ok', 'not_applicable'], true);
        }));
        if ($notOk) return ['lane' => 'unknown', 'reasons' => $notOk];

        return ['lane' => 'healthy', 'reasons' => []];
    }

    /** @param string[] $lanes */
    public static function rollUp(array $lanes): string
    {
        if ($lanes === []) return 'needs_setup';
        if (in_array('critical', $lanes, true)) return 'critical';
        if (in_array('warning', $lanes, true)) return 'warning';
        if (array_unique($lanes) === ['needs_setup']) return 'needs_setup';
        if (count(array_unique($lanes)) === 1 && $lanes[0] === 'healthy') return 'healthy';
        return 'unknown';
    }
}
