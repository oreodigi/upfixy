<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** ACC-003/004: workspaces and the switcher list. Members: MemberController / InvitationController. */
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
}
