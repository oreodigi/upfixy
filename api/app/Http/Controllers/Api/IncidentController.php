<?php

namespace App\Http\Controllers\Api;

use App\Monitoring\IssueLibrary;
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** MON-008 incidents (SCR-02): list, detail with timeline, acknowledge. RLS limits rows to visible websites. */
class IncidentController
{
    private const COLUMNS = "i.id, i.website_id, w.display_name AS website, t.host, i.status, i.root_kind, i.result_code,
                             i.opened_at, i.first_failure_at, i.last_failure_at, i.resolved_at, i.acknowledged_at,
                             w.client_id";

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:open,resolved,all'], 'website_id' => ['nullable', 'uuid']]);
        $where = [];
        $args = [];
        if (($data['status'] ?? 'open') !== 'all') { $where[] = 'i.status = ?::incident_status'; $args[] = $data['status'] ?? 'open'; }
        if (!empty($data['website_id'])) { $where[] = 'i.website_id = ?'; $args[] = $data['website_id']; }
        $rows = DB::select('SELECT ' . self::COLUMNS . ' FROM incidents i JOIN websites w ON w.id = i.website_id
                              JOIN monitor_targets t ON t.id = i.monitor_target_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.status, i.opened_at DESC LIMIT 100', $args);
        return response()->json(['data' => array_map([$this, 'present'], $rows)]);
    }

    public function show(string $incident): JsonResponse
    {
        $row = DB::selectOne('SELECT ' . self::COLUMNS . ' FROM incidents i JOIN websites w ON w.id = i.website_id
                                JOIN monitor_targets t ON t.id = i.monitor_target_id WHERE i.id = ?', [$incident]);
        if (!$row) abort(404);
        $out = $this->present($row);
        $out['events'] = DB::select('SELECT kind, result_code, at FROM incident_events WHERE incident_id = ? ORDER BY at, id', [$incident]);
        return response()->json($out);
    }

    public function acknowledge(string $incident): JsonResponse
    {
        $before = DB::selectOne('SELECT acknowledged_at FROM incidents WHERE id = ?', [$incident]);
        try {
            $at = DB::selectOne('SELECT acknowledge_incident(?) AS at', [$incident])->at;
        } catch (QueryException $e) {
            $msg = $e->errorInfo[2] ?? '';
            if (str_contains($msg, 'not found')) abort(404);
            if (str_contains($msg, 'not allowed')) {
                Audit::record('incident.acknowledge', 'denied', 'incident', $incident);
                abort(403, 'Only staff and the client owner can acknowledge incidents.');
            }
            throw $e;
        }
        if ($before?->acknowledged_at === null) Audit::record('incident.acknowledged', 'allowed', 'incident', $incident);
        return response()->json(['acknowledged_at' => $at]);
    }

    private function present(object $r): array
    {
        $a = (array) $r;
        $a['title'] = IssueLibrary::title($r->result_code);
        return $a;
    }
}
