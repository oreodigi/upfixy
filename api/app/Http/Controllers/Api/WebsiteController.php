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

    public function show(string $website): JsonResponse
    {
        $row = DB::selectOne('SELECT id, display_name, client_id, created_at FROM websites WHERE id = ?', [$website]);
        if (!$row) abort(404);
        return response()->json($row);
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
