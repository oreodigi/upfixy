<?php

namespace App\Http\Controllers\Api;

use App\Domain\UrlGuard;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** AST-001: add a website by URL with no credentials; exact target kept. */
class WebsiteController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::select(
            "SELECT w.id, w.display_name, w.client_id,
                    coalesce(json_agg(json_build_object('id', t.id, 'url', t.scheme || '://' || t.host ||
                      CASE WHEN (t.scheme = 'https' AND t.port = 443) OR (t.scheme = 'http' AND t.port = 80) THEN '' ELSE ':' || t.port END || t.path,
                      'state', t.state)) FILTER (WHERE t.id IS NOT NULL), '[]') AS targets
               FROM websites w LEFT JOIN monitor_targets t ON t.website_id = w.id
              GROUP BY w.id ORDER BY w.display_name")]);
    }

    /** SCR-07 website detail: targets, latest readings, ownership, requests. */
    public function show(string $website): JsonResponse
    {
        $row = DB::selectOne(
            'SELECT w.id, w.display_name, w.client_id, c.name AS client_name, w.created_at
               FROM websites w LEFT JOIN clients c ON c.id = w.client_id WHERE w.id = ?', [$website]);
        if (!$row) abort(404);
        $row->targets = DB::select(
            "SELECT id, host, state, interval_seconds, scheme || '://' || host ||
                    CASE WHEN (scheme = 'https' AND port = 443) OR (scheme = 'http' AND port = 80) THEN '' ELSE ':' || port END || path AS url
               FROM monitor_targets WHERE website_id = ? ORDER BY host", [$website]);
        $status = \App\Domain\SiteStatus::rows($website)[0] ?? null;
        $row->lane = $status['lane'] ?? 'needs_setup';
        $row->last_verified_at = $status['last_verified_at'] ?? null;
        $row->checks = $status['checks'] ?? [];
        $row->coverage = $status['coverage'] ?? null;
        $row->verified_hosts = $status['verified_hosts'] ?? [];
        $row->requests = DB::select(
            "SELECT t.id, t.title, t.state, t.updated_at FROM tickets t WHERE t.website_id = ?
              ORDER BY (t.state IN ('resolved', 'closed')), t.updated_at DESC LIMIT 20", [$website]);
        return response()->json($row);
    }

    /** Reading history, newest first (SCR-07 Activity). Private readings follow ownership proof (RLS). */
    public function history(Request $request, string $website): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:http,tls,dns'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$website])) abort(404);
        $args = [$website];
        // id is the evidence ID that diagnostics cite (FRD §25.3); detail is redacted at capture.
        $kind = '';
        if (!empty($data['kind'])) { $kind = 'AND o.check_kind = ?::check_type'; $args[] = $data['kind']; }
        $args[] = $data['limit'] ?? 50;
        $rows = DB::select(
            "SELECT o.id, o.observed_at, t.host, o.check_kind::text AS kind, o.severity::text AS severity,
                    o.source::text AS source, o.result_code, o.region, o.cycle_no, o.detail
               FROM observations o JOIN monitor_targets t ON t.id = o.monitor_target_id
              WHERE t.website_id = ? {$kind} ORDER BY o.observed_at DESC LIMIT ?", $args);
        foreach ($rows as $r) $r->detail = json_decode($r->detail, true);
        return response()->json(['data' => $rows]);
    }

    /** KB-003 "Check now": queues a live check; the stored result keeps its own timestamp until it arrives. */
    public function recheck(string $website): JsonResponse
    {
        try {
            $n = DB::selectOne('SELECT request_recheck(?) AS n', [$website])->n;
        } catch (\Illuminate\Database\QueryException $e) {
            $msg = $e->errorInfo[2] ?? '';
            if (str_contains($msg, 'not found')) abort(404);
            if (str_contains($msg, 'billing')) abort(403, 'Billing contacts cannot run checks.');
            throw $e;
        }
        return response()->json($n > 0
            ? ['queued' => $n, 'message' => 'A live check is queued. Results usually arrive within a minute or two.']
            : ['queued' => 0, 'message' => 'A check was requested in the last 2 minutes. Showing the latest stored results.'], 202);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string'],
            'client_id' => ['nullable', 'uuid'],
            'display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $r = UrlGuard::normalize($data['url']);
        if (!$r['ok']) {
            return response()->json(['message' => $r['message'], 'errors' => ['url' => [$r['message']]], 'code' => $r['code']], 422);
        }
        $t = $r['target'];
        $hostForDb = $t['host'];

        try {
            $id = DB::transaction(function () use ($data, $t, $hostForDb) {
                $id = DB::selectOne('INSERT INTO websites(workspace_id, client_id, display_name) VALUES (?, ?, ?) RETURNING id',
                    [TenantContext::workspaceId(), $data['client_id'] ?? null, $data['display_name'] ?? trim($hostForDb, '[]')])->id;
                DB::insert('INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, path) VALUES (?, ?, ?, ?, ?, ?)',
                    [TenantContext::workspaceId(), $id, $t['scheme'], $hostForDb, $t['port'], $t['path']]);
                return $id;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json(['message' => 'This exact address is already monitored in this workspace.'], 409);
            }
            throw $e; // RLS denials are mapped to 403 globally
        }

        Audit::record('website.created', 'allowed', 'website', $id, ['target' => $t]);
        return response()->json([
            'id' => $id,
            'target' => $t,
            'monitor_state' => 'pending_validation',
            'note' => $r['droppedQuery'] ? 'The query string was removed; only the page path is monitored.' : null,
        ], 201);
    }
}
