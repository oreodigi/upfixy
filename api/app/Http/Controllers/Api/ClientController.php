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

    /** Contract terms. agency_may_pay (FIX-B) can only be changed by the owner (DB-enforced) with fresh MFA (route). */
    public function update(Request $request, string $client): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'agency_may_pay' => ['sometimes', 'boolean'],
            'client_self_service' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) abort(422, 'Nothing to change.');
        $this->requireAdmin('client.update');
        if (array_key_exists('agency_may_pay', $data) && ($reason = \App\Http\Middleware\RequireFreshMfa::missing($request))) {
            Audit::record('client.payment_authority', 'denied', 'client', $client, ['reason' => $reason]);
            return \App\Http\Middleware\RequireFreshMfa::response($reason);
        }
        $sets = implode(', ', array_map(fn ($k) => "{$k} = ?", array_keys($data)));
        $n = DB::update("UPDATE clients SET {$sets} WHERE id = ?", [...array_values($data), $client]);
        if ($n === 0) abort(404);
        Audit::record('client.updated', 'allowed', 'client', $client, $data);
        return response()->json(null, 204);
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

    /** Remove a person from a client. Their open requests for that client return to the unassigned pool. */
    public function unassign(string $client, string $user): JsonResponse
    {
        $this->requireAdmin('client.unassign');
        $n = DB::delete('DELETE FROM client_assignments WHERE client_id = ? AND user_id = ?', [$client, $user]);
        if ($n === 0) abort(404);
        $freed = DB::update(
            "UPDATE tickets SET assignee_id = NULL, version = version + 1
              WHERE assignee_id = ? AND state NOT IN ('resolved', 'closed')
                AND website_id IN (SELECT id FROM websites WHERE client_id = ?)", [$user, $client]);
        Audit::record('client.unassigned', 'allowed', 'client', $client, ['user_id' => $user, 'requests_unassigned' => $freed]);
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
