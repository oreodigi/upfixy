<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** ACC-006: client profiles and staff/client-user assignments. */
class ClientController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::select('SELECT id, name, agency_may_pay, client_self_service FROM clients ORDER BY name')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $this->requireAdmin('client.create');
        $id = DB::selectOne('INSERT INTO clients(workspace_id, name) VALUES (?, ?) RETURNING id',
            [TenantContext::workspaceId(), $data['name']])->id;
        Audit::record('client.created', 'allowed', 'client', $id);
        return response()->json(['id' => $id], 201);
    }

    public function assign(Request $request, string $client): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'uuid']]);
        $this->requireAdmin('client.assign');
        if (!DB::selectOne('SELECT 1 AS x FROM clients WHERE id = ?', [$client])) abort(404);
        $member = DB::selectOne('SELECT 1 AS x FROM memberships WHERE workspace_id = ? AND user_id = ?',
            [TenantContext::workspaceId(), $data['user_id']]);
        if (!$member) abort(422, 'That person is not a member of this workspace.');
        DB::insert('INSERT INTO client_assignments(workspace_id, client_id, user_id) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
            [TenantContext::workspaceId(), $client, $data['user_id']]);
        Audit::record('client.assigned', 'allowed', 'client', $client, ['user_id' => $data['user_id']]);
        return response()->json(null, 204);
    }

    private function requireAdmin(string $action): void
    {
        if (!in_array(TenantContext::role(), ['owner', 'admin'], true)) {
            Audit::record($action, 'denied');
            abort(403, 'Only workspace owners and admins can do this.');
        }
    }
}
