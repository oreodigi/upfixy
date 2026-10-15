<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** ACC-003/004/005: workspaces, switcher list, members. */
class WorkspaceController
{
    /** Workspaces the caller belongs to (RLS: own memberships only). */
    public function index(): JsonResponse
    {
        $rows = DB::select(
            'SELECT w.id, w.name, w.kind, m.role FROM memberships m JOIN workspaces w ON w.id = m.workspace_id
              WHERE m.user_id = ? AND m.suspended_at IS NULL ORDER BY w.name', [TenantContext::userId()]);
        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'kind' => ['required', 'in:solo,agency']]);
        $id = DB::selectOne('SELECT create_workspace(?, ?::workspace_kind) AS id', [$data['name'], $data['kind']])->id;
        TenantContext::apply(TenantContext::userId(), $id);
        Audit::record('workspace.created', 'allowed', 'workspace', $id);
        return response()->json(['id' => $id], 201);
    }

    /**
     * Add an existing user to this workspace (owner/admin; enforced by RLS too).
     * Email invitations with acceptance are a later story (US-015).
     */
    public function addMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:admin,technician,billing,client_owner,client_staff'],
        ]);
        if (!in_array(TenantContext::role(), ['owner', 'admin'], true)) {
            Audit::record('member.add', 'denied');
            abort(403, 'Only workspace owners and admins can add members.');
        }
        $user = DB::selectOne('SELECT id FROM users WHERE email = ?', [$data['email']]);
        if (!$user) {
            abort(422, 'No Upfixy account uses that email yet. Ask them to sign up first.');
        }
        DB::insert('INSERT INTO memberships(workspace_id, user_id, role) VALUES (?, ?, ?::member_role)
                    ON CONFLICT (workspace_id, user_id) DO NOTHING',
            [TenantContext::workspaceId(), $user->id, $data['role']]);
        Audit::record('member.added', 'allowed', 'user', $user->id, ['role' => $data['role']]);
        return response()->json(['user_id' => $user->id, 'role' => $data['role']], 201);
    }
}
