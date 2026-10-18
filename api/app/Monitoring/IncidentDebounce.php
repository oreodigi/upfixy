<?php

namespace App\Monitoring;

/**
 * Same rule as packages/core/src/incidents.ts, checked against the same
 * vectors (packages/core/vectors/incidents.json). Works from the stored
 * cycle history plus whether an incident is open, so it is idempotent: a
 * re-run of the same cycle reaches the same decision.
 */
final class IncidentDebounce
{
    public const OPEN_AFTER = 3;
    public const RESOLVE_AFTER = 2;

    /** Map a stored check-run outcome to a debounce outcome. */
    public static function cycleOutcome(string $runOutcome): string
    {
        return match ($runOutcome) {
            'target_failure' => 'fail',
            'ok', 'warning' => 'ok',
            default => 'probe_error', // probe_error, probe_blocked, inconclusive: never counts either way
        };
    }

    /**
     * @param string[] $outcomes debounce outcomes, oldest first, ending with the current cycle
     * @return 'open'|'resolve'|'fail'|'none'
     */
    public static function decide(bool $open, array $outcomes): string
    {
        $current = end($outcomes);
        if ($current === 'probe_error' || $current === false) return 'none';
        $run = 0;
        for ($i = count($outcomes) - 1; $i >= 0; $i--) {
            if ($outcomes[$i] === 'probe_error') continue;
            if ($outcomes[$i] !== $current) break;
            $run++;
        }
        if ($current === 'fail') {
            return $open ? 'fail' : ($run >= self::OPEN_AFTER ? 'open' : 'none');
        }
        return $open && $run >= self::RESOLVE_AFTER ? 'resolve' : 'none';
    }

    /**
     * Folds a whole sequence (for the shared vectors).
     * @return array{open:bool, openedAt:?int, resolvedAt:?int} 1-based cycle numbers
     */
    public static function fold(array $outcomes): array
    {
        $open = false; $openedAt = null; $resolvedAt = null;
        foreach ($outcomes as $i => $_) {
            $action = self::decide($open, array_slice($outcomes, 0, $i + 1));
            if ($action === 'open') { $open = true; $openedAt = $i + 1; $resolvedAt = null; }
            if ($action === 'resolve') { $open = false; $resolvedAt = $i + 1; }
        }
        return ['open' => $open, 'openedAt' => $openedAt, 'resolvedAt' => $resolvedAt];
    }

    /** Worst outcome wins; a probe-side problem outranks warnings so it never reads as "fine". */
    public static function runOutcome(array $readings): string
    {
        $rank = ['target_failure' => 6, 'probe_error' => 5, 'inconclusive' => 4, 'probe_blocked' => 3, 'warning' => 2, 'ok' => 1];
        $worst = 'ok';
        foreach ($readings as $r) if ($rank[$r->outcome] > $rank[$worst]) $worst = $r->outcome;
        return $worst;
    }

    /** Root event for deduplication: DNS explains TLS and HTTP; TLS explains HTTPS errors. */
    public static function root(array $readings): ?Reading
    {
        foreach (['dns', 'tls', 'http'] as $k) {
            foreach ($readings as $r) if ($r->kind === $k && $r->isTargetFailure()) return $r;
        }
        return null;
    }
}
