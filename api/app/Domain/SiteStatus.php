<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes per-website health from the latest observation of each check on
 * each monitored address (FRD §15.2). Lanes are derived on every read and
 * never stored, so a reading that goes stale changes the lane by itself.
 * Every query runs under row-level security: only visible sites are returned.
 */
final class SiteStatus
{
    /** @return array<int, array<string, mixed>> */
    public static function rows(?string $websiteId = null): array
    {
        $now = CarbonImmutable::now();
        $where = $websiteId ? 'WHERE w.id = ?' : '';
        $args = $websiteId ? [$websiteId] : [];

        $targets = DB::select(
            "SELECT w.id AS website_id, w.client_id, w.display_name, t.id AS target_id, t.host, t.state, t.interval_seconds
               FROM websites w LEFT JOIN monitor_targets t ON t.website_id = w.id {$where}
              ORDER BY w.display_name, t.host", $args);
        $latest = collect(DB::select(
            "SELECT DISTINCT ON (o.monitor_target_id, o.check_kind)
                    o.monitor_target_id, o.check_kind::text AS kind, o.severity::text AS severity,
                    o.source::text AS source, o.observed_at, o.result_code
               FROM observations o " . ($websiteId ? 'JOIN monitor_targets t ON t.id = o.monitor_target_id WHERE t.website_id = ?' : '') . "
              ORDER BY o.monitor_target_id, o.check_kind, o.observed_at DESC", $args))->groupBy('monitor_target_id');
        $openTickets = collect(DB::select(
            "SELECT website_id, count(*)::int AS n FROM tickets WHERE state NOT IN ('resolved', 'closed') GROUP BY website_id"))
            ->pluck('n', 'website_id');
        $verifiedHosts = collect(DB::select(
            "SELECT website_id, host FROM ownership_claims
              WHERE status = 'verified' AND expires_at > now() AND revoked_at IS NULL"))
            ->groupBy('website_id')->map(fn ($g) => $g->pluck('host')->unique()->values()->all());

        $rows = [];
        foreach (collect($targets)->groupBy('website_id') as $websiteId => $ts) {
            $first = $ts->first();
            $targetLanes = [];
            $checks = [];
            $verified = [];
            $hosts = [];
            foreach ($ts as $t) {
                if ($t->target_id === null) continue;
                $hosts[] = $t->host;
                $readings = [];
                foreach ($latest->get($t->target_id, collect()) as $o) {
                    $at = CarbonImmutable::parse($o->observed_at);
                    $fresh = Health::freshness($at, $t->interval_seconds, $now);
                    $readings[] = ['kind' => $o->kind, 'severity' => $o->severity, 'freshness' => $fresh];
                    $checks[] = ['target_id' => $t->target_id, 'host' => $t->host, 'kind' => $o->kind, 'severity' => $o->severity,
                        'freshness' => $fresh, 'source' => $o->source, 'result_code' => $o->result_code,
                        'observed_at' => $at->toIso8601String()];
                    if ($fresh === 'fresh') $verified[] = $at;
                }
                $targetLanes[] = Health::site($t->state === 'active', $readings)['lane'];
            }
            $rows[] = [
                'id' => $websiteId,
                'client_id' => $first->client_id,
                'display_name' => $first->display_name,
                'hosts' => $hosts,
                'lane' => Health::rollUp($targetLanes),
                'last_verified_at' => $verified ? collect($verified)->min()->toIso8601String() : null,
                'checks' => $checks,
                'open_requests' => (int) ($openTickets[$websiteId] ?? 0),
                'verified_hosts' => $verifiedHosts->get($websiteId, []),
                // Not connected is coverage, not severity (§15.2); connectors arrive in M4.
                'coverage' => ['hosting' => 'not_connected', 'backup' => 'not_connected', 'security' => 'not_assessed'],
            ];
        }
        return $rows;
    }

    /** Fresh warning/critical checks, worst first: the "what needs attention" list. */
    public static function issues(array $rows, int $limit = 10): array
    {
        $rank = ['critical' => 0, 'warning' => 1];
        $out = [];
        foreach ($rows as $r) {
            foreach ($r['checks'] as $c) {
                if ($c['freshness'] === 'fresh' && isset($rank[$c['severity']])) {
                    $out[] = ['website_id' => $r['id'], 'website' => $r['display_name'], 'client_id' => $r['client_id']] + $c;
                }
            }
        }
        usort($out, fn ($a, $b) => [$rank[$a['severity']], $b['observed_at']] <=> [$rank[$b['severity']], $a['observed_at']]);
        return array_slice($out, 0, $limit);
    }
}
