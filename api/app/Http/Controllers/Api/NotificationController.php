<?php

namespace App\Http\Controllers\Api;

use App\Monitoring\IssueLibrary;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** ALR-001: in-app inbox and alert preferences. Every row is the caller's own (RLS). */
class NotificationController
{
    public function index(): JsonResponse
    {
        $rows = DB::select(
            "SELECT n.id, n.kind, n.created_at, n.read_at, i.id AS incident_id, i.result_code, i.status,
                    i.website_id, w.display_name AS website
               FROM notifications n JOIN incidents i ON i.id = n.incident_id JOIN websites w ON w.id = i.website_id
              ORDER BY n.created_at DESC LIMIT 100");
        foreach ($rows as $r) $r->title = IssueLibrary::title($r->result_code);
        return response()->json(['data' => $rows, 'unread' => count(array_filter($rows, fn ($r) => $r->read_at === null))]);
    }

    public function read(string $notification): JsonResponse
    {
        $n = DB::update('UPDATE notifications SET read_at = coalesce(read_at, now()) WHERE id = ?', [$notification]);
        if ($n === 0) abort(404);
        return response()->json(null, 204);
    }

    public function readAll(): JsonResponse
    {
        DB::update('UPDATE notifications SET read_at = now() WHERE read_at IS NULL');
        return response()->json(null, 204);
    }

    public function prefs(): JsonResponse
    {
        $rows = DB::select('SELECT client_id, email, in_app FROM notification_prefs');
        $ws = collect($rows)->firstWhere('client_id', null);
        return response()->json([
            'workspace' => ['email' => $ws->email ?? true, 'in_app' => $ws->in_app ?? true],
            'clients' => array_values(array_filter($rows, fn ($r) => $r->client_id !== null)),
        ]);
    }

    /** PUT {client_id?: uuid|null, email: bool, in_app: bool}; DELETE-like reset with {client_id, reset: true}. */
    public function updatePrefs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'uuid'],
            'email' => ['required_without:reset', 'boolean'],
            'in_app' => ['required_without:reset', 'boolean'],
            'reset' => ['sometimes', 'accepted'],
        ]);
        $client = $data['client_id'] ?? null;
        if ($client && !DB::selectOne('SELECT 1 AS x FROM clients WHERE id = ?', [$client])) abort(404);
        $match = $client ? 'client_id = ?' : 'client_id IS NULL';
        $args = $client ? [$client] : [];
        DB::delete("DELETE FROM notification_prefs WHERE {$match}", $args);
        if (empty($data['reset'])) {
            DB::insert('INSERT INTO notification_prefs(workspace_id, user_id, client_id, email, in_app) VALUES (?, ?, ?, ?, ?)',
                [TenantContext::workspaceId(), TenantContext::userId(), $client, $data['email'], $data['in_app']]);
        }
        return $this->prefs();
    }
}
