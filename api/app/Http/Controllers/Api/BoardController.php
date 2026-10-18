<?php

namespace App\Http\Controllers\Api;

use App\Domain\Health;
use App\Domain\SiteStatus;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * BRD-001/002, SCR-02/06: health boards, overview and client detail.
 * Lanes are computed on the server (§15.2); never stored, never set by a user.
 */
class BoardController
{
    public function websites(): JsonResponse
    {
        return response()->json(['data' => SiteStatus::rows()]);
    }

    public function clients(): JsonResponse
    {
        $sites = collect(SiteStatus::rows());
        $staff = $this->staffByClient();
        $clients = DB::select('SELECT id, name FROM clients ORDER BY name');
        return response()->json(['data' => array_map(fn ($c) => $this->clientRow($c, $sites, $staff), $clients)]);
    }

    /** SCR-06 client detail. */
    public function client(string $client): JsonResponse
    {
        $c = DB::selectOne('SELECT id, name, agency_may_pay, client_self_service, created_at FROM clients WHERE id = ?', [$client]);
        if (!$c) abort(404);
        $row = $this->clientRow($c, collect(SiteStatus::rows()), $this->staffByClient());
        $row['agency_may_pay'] = $c->agency_may_pay;
        $row['client_self_service'] = $c->client_self_service;
        $row['client_users'] = DB::select(
            "SELECT a.user_id, u.email, m.role FROM client_assignments a
               JOIN memberships m ON m.workspace_id = a.workspace_id AND m.user_id = a.user_id AND m.suspended_at IS NULL
               LEFT JOIN users u ON u.id = a.user_id
              WHERE a.client_id = ? AND m.role IN ('client_owner', 'client_staff') ORDER BY m.role, u.email", [$client]);
        return response()->json($row);
    }

    /**
     * Measured trends for dashboards (UX-01…03). Read-only aggregation of existing readings under the
     * caller's row-level security; nothing is estimated or filled in. Availability = share of HTTP checks
     * in the window that were not critical; response time = average measured `ms_total`. Hours with no
     * readings are returned as null (a gap), never as zero.
     */
    public function metrics(Request $request): JsonResponse
    {
        $hours = 24;
        $per = DB::select(
            "SELECT t.website_id,
                    count(*) FILTER (WHERE o.check_kind = 'http')::int AS http_checks,
                    count(*) FILTER (WHERE o.check_kind = 'http' AND o.severity IN ('ok', 'warning'))::int AS http_up,
                    round(avg((o.detail->>'ms_total')::numeric) FILTER (WHERE o.check_kind = 'http' AND (o.detail->>'ms_total') IS NOT NULL))::int AS avg_ms
               FROM observations o JOIN monitor_targets t ON t.id = o.monitor_target_id
              WHERE o.observed_at > now() - make_interval(hours => ?)
              GROUP BY t.website_id", [$hours]);
        $latest = DB::select(
            "SELECT DISTINCT ON (t.website_id, o.check_kind) t.website_id, o.check_kind::text AS kind, o.observed_at,
                    (o.detail->>'ms_total')::int AS ms, (o.detail->>'days_left')::int AS days_left, o.detail->>'not_after' AS not_after
               FROM observations o JOIN monitor_targets t ON t.id = o.monitor_target_id
              WHERE o.check_kind IN ('http', 'tls')
              ORDER BY t.website_id, o.check_kind, o.observed_at DESC");
        $hourly = DB::select(
            "SELECT t.website_id, date_trunc('hour', o.observed_at) AS h,
                    count(*)::int AS checks, count(*) FILTER (WHERE o.severity IN ('ok', 'warning'))::int AS up,
                    round(avg((o.detail->>'ms_total')::numeric))::int AS avg_ms
               FROM observations o JOIN monitor_targets t ON t.id = o.monitor_target_id
              WHERE o.check_kind = 'http' AND o.observed_at > date_trunc('hour', now()) - make_interval(hours => ?)
              GROUP BY t.website_id, h", [$hours - 1]);

        $buckets = [];
        $start = now()->startOfHour()->subHours($hours - 1);
        for ($i = 0; $i < $hours; $i++) $buckets[] = $start->copy()->addHours($i)->toIso8601String();
        $index = array_flip($buckets);
        $sites = [];
        foreach ($per as $p) {
            $sites[$p->website_id] = ['http_checks' => $p->http_checks, 'availability_pct' => $p->http_checks ? round(100 * $p->http_up / $p->http_checks, 2) : null,
                'avg_ms' => $p->avg_ms, 'last_ms' => null, 'tls_days_left' => null, 'tls_not_after' => null, 'ms_series' => array_fill(0, $hours, null)];
        }
        foreach ($latest as $l) {
            $sites[$l->website_id] ??= ['http_checks' => 0, 'availability_pct' => null, 'avg_ms' => null, 'last_ms' => null, 'tls_days_left' => null,
                'tls_not_after' => null, 'ms_series' => array_fill(0, $hours, null)];
            if ($l->kind === 'http') $sites[$l->website_id]['last_ms'] = $l->ms;
            if ($l->kind === 'tls') { $sites[$l->website_id]['tls_days_left'] = $l->days_left; $sites[$l->website_id]['tls_not_after'] = $l->not_after; }
        }
        $total = array_fill(0, $hours, ['checks' => 0, 'up' => 0, 'ms_sum' => 0, 'ms_n' => 0]);
        foreach ($hourly as $h) {
            $i = $index[\Carbon\Carbon::parse($h->h)->toIso8601String()] ?? null;
            if ($i === null) continue;
            if (isset($sites[$h->website_id])) $sites[$h->website_id]['ms_series'][$i] = $h->avg_ms;
            $total[$i]['checks'] += $h->checks;
            $total[$i]['up'] += $h->up;
            if ($h->avg_ms !== null) { $total[$i]['ms_sum'] += $h->avg_ms * $h->checks; $total[$i]['ms_n'] += $h->checks; }
        }
        $series = [];
        foreach ($buckets as $i => $t) {
            $b = $total[$i];
            $series[] = ['hour' => $t, 'checks' => $b['checks'], 'availability_pct' => $b['checks'] ? round(100 * $b['up'] / $b['checks'], 2) : null,
                'avg_ms' => $b['ms_n'] ? (int) round($b['ms_sum'] / $b['ms_n']) : null];
        }
        $activity = DB::select(
            "(SELECT 'incident_' || e.kind AS kind, e.at, w.display_name AS website, w.id AS website_id, coalesce(e.result_code, i.result_code) AS detail, NULL::uuid AS ticket_id
                FROM incident_events e JOIN incidents i ON i.id = e.incident_id JOIN websites w ON w.id = i.website_id
               WHERE e.kind IN ('opened', 'resolved') ORDER BY e.at DESC LIMIT 12)
             UNION ALL
             (SELECT 'ticket_' || e.to_state::text, e.created_at, w.display_name, w.id, t.title, t.id
                FROM ticket_events e JOIN tickets t ON t.id = e.ticket_id JOIN websites w ON w.id = t.website_id
               ORDER BY e.created_at DESC LIMIT 12)
             ORDER BY 2 DESC LIMIT 12");
        return response()->json(['window_hours' => $hours, 'generated_at' => now()->toIso8601String(), 'series' => $series,
            'websites' => (object) $sites, 'activity' => $activity]);
    }

    /** SCR-02 overview: what needs attention now, across everything the caller can see. */
    public function overview(): JsonResponse
    {
        $rows = SiteStatus::rows();
        $lanes = array_fill_keys(['critical', 'warning', 'unknown', 'needs_setup', 'healthy'], 0);
        foreach ($rows as $r) $lanes[$r['lane']]++;
        $byState = collect(DB::select(
            "SELECT state::text AS state, count(*)::int AS n FROM tickets WHERE state NOT IN ('closed') GROUP BY state"))->pluck('n', 'state');
        $mine = DB::select(
            "SELECT t.id, t.title, t.state, w.display_name AS website FROM tickets t JOIN websites w ON w.id = t.website_id
              WHERE t.assignee_id = ? AND t.state NOT IN ('resolved', 'closed') ORDER BY t.updated_at DESC LIMIT 10",
            [TenantContext::userId()]);
        $awaitingDecision = DB::select(
            "SELECT t.id, t.title, w.display_name AS website FROM tickets t JOIN websites w ON w.id = t.website_id
              WHERE t.state = 'quote_pending' ORDER BY t.updated_at LIMIT 10");
        return response()->json([
            'websites' => ['total' => count($rows), 'by_lane' => $lanes],
            'issues' => SiteStatus::issues($rows, 10),
            'requests' => ['by_state' => $byState, 'assigned_to_me' => $mine, 'awaiting_quote_decision' => $awaitingDecision],
        ]);
    }

    private function clientRow(object $c, $sites, array $staff): array
    {
        $own = $sites->where('client_id', $c->id)->values();
        $verified = $own->pluck('last_verified_at');
        return [
            'id' => $c->id,
            'name' => $c->name,
            'lane' => Health::rollUp($own->pluck('lane')->all()),
            'website_count' => $own->count(),
            'impacted_count' => $own->where('lane', '!=', 'healthy')->count(),
            'open_requests' => $own->sum('open_requests'),
            // Only "verified" for the client when every site has a fresh reading.
            'last_verified_at' => $own->isNotEmpty() && $verified->every(fn ($v) => $v !== null) ? $verified->min() : null,
            'staff' => $staff[$c->id] ?? [],
            'websites' => $own->all(),
        ];
    }

    /** Technicians assigned per client. Emails appear only where the caller may see them (users RLS). */
    private function staffByClient(): array
    {
        $out = [];
        foreach (DB::select(
            "SELECT a.client_id, a.user_id, u.email FROM client_assignments a
               JOIN memberships m ON m.workspace_id = a.workspace_id AND m.user_id = a.user_id
                                 AND m.suspended_at IS NULL AND m.role = 'technician'
               LEFT JOIN users u ON u.id = a.user_id ORDER BY u.email") as $r) {
            $out[$r->client_id][] = ['user_id' => $r->user_id, 'email' => $r->email];
        }
        return $out;
    }
}
