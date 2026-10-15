<?php

namespace App\Http\Controllers\Api;

use App\Domain\Health;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * BRD-001/002: health boards. Lanes are computed here (server is the
 * authority, §15.2); they are never stored and never set by a user action.
 */
class BoardController
{
    public function websites(): JsonResponse
    {
        return response()->json(['data' => $this->siteRows()]);
    }

    public function clients(): JsonResponse
    {
        $sites = collect($this->siteRows());
        $clients = DB::select('SELECT id, name FROM clients ORDER BY name');
        $data = array_map(function ($c) use ($sites) {
            $own = $sites->where('client_id', $c->id)->values();
            return [
                'id' => $c->id,
                'name' => $c->name,
                'lane' => Health::rollUp($own->pluck('lane')->all()),
                'website_count' => $own->count(),
                'impacted_count' => $own->where('lane', '!=', 'healthy')->count(),
                'last_verified_at' => $own->every(fn ($s) => $s['last_verified_at'] !== null)
                    ? $own->min('last_verified_at') : null,
                'websites' => $own->all(),
            ];
        }, $clients);
        return response()->json(['data' => $data]);
    }

    /** One row per visible website with its computed lane (RLS limits what is visible). */
    private function siteRows(): array
    {
        $now = CarbonImmutable::now();
        $targets = DB::select(
            "SELECT w.id AS website_id, w.client_id, w.display_name, t.id AS target_id, t.state, t.interval_seconds
               FROM websites w LEFT JOIN monitor_targets t ON t.website_id = w.id ORDER BY w.display_name");
        $latest = collect(DB::select(
            'SELECT DISTINCT ON (o.monitor_target_id, o.check_kind)
                    o.monitor_target_id, o.check_kind::text AS kind, o.severity::text AS severity, o.observed_at, o.result_code
               FROM observations o
              ORDER BY o.monitor_target_id, o.check_kind, o.observed_at DESC'))->groupBy('monitor_target_id');

        $rows = [];
        foreach (collect($targets)->groupBy('website_id') as $websiteId => $ts) {
            $first = $ts->first();
            // A website's lane is the roll-up of its targets (apex and www are separate targets).
            $targetLanes = [];
            $checks = [];
            $verified = [];
            foreach ($ts as $t) {
                if ($t->target_id === null) continue;
                $readings = [];
                foreach ($latest->get($t->target_id, collect()) as $o) {
                    $at = CarbonImmutable::parse($o->observed_at);
                    $fresh = Health::freshness($at, $t->interval_seconds, $now);
                    $readings[] = ['kind' => $o->kind, 'severity' => $o->severity, 'freshness' => $fresh];
                    $checks[] = ['target_id' => $t->target_id, 'kind' => $o->kind, 'severity' => $o->severity,
                        'freshness' => $fresh, 'result_code' => $o->result_code, 'observed_at' => $at->toIso8601String()];
                    if ($fresh === 'fresh') $verified[] = $at;
                }
                $targetLanes[] = Health::site($t->state === 'active', $readings)['lane'];
            }
            $lane = Health::rollUp($targetLanes);
            $rows[] = [
                'id' => $websiteId,
                'client_id' => $first->client_id,
                'display_name' => $first->display_name,
                'lane' => $lane,
                'last_verified_at' => $verified ? collect($verified)->min()->toIso8601String() : null,
                'checks' => $checks,
                // Not connected is coverage, not severity (§15.2); connectors arrive in M4.
                'coverage' => ['hosting' => 'not_connected', 'backup' => 'not_connected', 'security' => 'not_assessed'],
            ];
        }
        return $rows;
    }
}
