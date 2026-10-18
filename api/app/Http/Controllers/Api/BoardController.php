<?php

namespace App\Http\Controllers\Api;

use App\Domain\Health;
use App\Domain\SiteStatus;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
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
