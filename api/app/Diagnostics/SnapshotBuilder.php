<?php

namespace App\Diagnostics;

use App\Domain\Health;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DX-001 evidence collection. Builds an immutable snapshot of what is known
 * about ONE website right now, from rows the caller can read (RLS): latest
 * reading per address and check, recent cycle history, the open incident,
 * and what is NOT connected. Only safe facts are copied from evidence detail.
 */
final class SnapshotBuilder
{
    /** Facts copied from observation detail, per check. Everything else stays behind. */
    private const FACTS = [
        'http' => ['status', 'ms_total', 'ms_ttfb', 'content_type'],
        'tls' => ['days_left', 'not_after', 'subject_cn', 'issuer_cn', 'sans', 'verified', 'chain_length'],
        'dns' => ['dnssec', 'disagreement', 'answers_with_checking_disabled'],
    ];

    public function build(string $websiteId): ?array
    {
        $site = DB::selectOne('SELECT id, display_name, client_id FROM websites WHERE id = ?', [$websiteId]);
        if (!$site) return null;
        $now = CarbonImmutable::now();
        $targets = DB::select('SELECT id, host, scheme, port, path, state, interval_seconds FROM monitor_targets WHERE website_id = ? ORDER BY host', [$websiteId]);
        $verified = collect(DB::select(
            "SELECT DISTINCT host FROM ownership_claims WHERE website_id = ? AND status = 'verified' AND expires_at > now() AND revoked_at IS NULL", [$websiteId]))->pluck('host')->all();

        $items = [];
        $history = [];
        foreach ($targets as $t) {
            $latest = DB::select(
                "SELECT DISTINCT ON (check_kind) id, check_kind::text AS kind, severity::text AS severity, source::text AS source,
                        result_code, observed_at, cycle_no, detail
                   FROM observations WHERE monitor_target_id = ? ORDER BY check_kind, observed_at DESC", [$t->id]);
            foreach ($latest as $o) {
                $at = CarbonImmutable::parse($o->observed_at);
                $detail = json_decode($o->detail ?? '{}', true) ?: [];
                $items[] = [
                    'evidence_id' => $o->id, 'target_id' => $t->id, 'host' => $t->host, 'kind' => $o->kind,
                    'severity' => $o->severity, 'result_code' => strtoupper($o->result_code), 'source' => $o->source,
                    'observed_at' => $at->toIso8601String(), 'cycle_no' => (int) $o->cycle_no,
                    'freshness' => Health::freshness($at, $t->interval_seconds, $now),
                    'facts' => self::facts($o->kind, $detail),
                ];
            }
            // How many of the last 6 cycles failed, per check (corroboration for rules).
            foreach (DB::select(
                "SELECT check_kind::text AS kind, count(*) FILTER (WHERE severity = 'critical')::int AS failed, count(*)::int AS cycles
                   FROM (SELECT check_kind, severity, row_number() OVER (PARTITION BY check_kind ORDER BY cycle_no DESC) AS rn
                           FROM observations WHERE monitor_target_id = ?) x WHERE rn <= 6 GROUP BY check_kind", [$t->id]) as $h) {
                $history["{$t->id}:{$h->kind}"] = ['failed' => $h->failed, 'cycles' => $h->cycles];
            }
        }
        foreach ($items as &$i) $i['recent'] = $history["{$i['target_id']}:{$i['kind']}"] ?? ['failed' => 0, 'cycles' => 0];
        unset($i);

        $incident = DB::selectOne("SELECT id, root_kind::text AS root_kind, result_code, first_failure_at FROM incidents WHERE website_id = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1", [$websiteId]);

        $snapshot = [
            'website_id' => $site->id,
            'website' => $site->display_name,
            'targets' => array_map(fn ($t) => ['target_id' => $t->id, 'host' => $t->host, 'scheme' => $t->scheme, 'state' => $t->state], $targets),
            'items' => $items,
            'incident' => $incident ? (array) $incident : null,
            // What Upfixy cannot see (DX-001 "skipped — not connected"). Connectors arrive in M4.
            'coverage' => [
                'hosting' => 'not_connected', 'dns_provider' => 'not_connected', 'registrar' => 'not_connected', 'backup' => 'not_connected',
                'ownership_verified_hosts' => array_values($verified),
            ],
        ];
        $snapshot['hash'] = hash('sha256', json_encode([$snapshot['items'], $snapshot['incident'], $snapshot['coverage']]));
        return $snapshot;
    }

    private static function facts(string $kind, array $d): array
    {
        $out = array_intersect_key($d, array_flip(self::FACTS[$kind] ?? []));
        if ($kind === 'http') {
            $chain = $d['chain'] ?? [];
            $out['redirects'] = max(0, count($chain) - 1);
            $out['errors'] = array_values(array_filter(array_map(fn ($h) => $h['error'] ?? null, $chain)));
            $out['https_to_http'] = count($chain) > 1 && str_starts_with($chain[0]['url'] ?? '', 'https:')
                && str_starts_with(end($chain)['url'] ?? '', 'http:');
        }
        if ($kind === 'dns') {
            $out['resolver_rcodes'] = array_map(fn ($r) => ($r['status'] ?? '') === 'answered' ? ($r['rcode'] ?? null) : ($r['status'] ?? null), $d['resolvers'] ?? []);
        }
        if ($kind === 'tls' && isset($out['sans'])) $out['sans'] = array_slice($out['sans'], 0, 10);
        return $out;
    }
}
