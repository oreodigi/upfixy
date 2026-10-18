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
            'SELECT w.id, w.name, w.kind, m.role, w.closed_at FROM memberships m JOIN workspaces w ON w.id = m.workspace_id
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
     * NFR-14 soft close (owner, fresh MFA, typed name). Stops monitoring, revokes provider credentials,
     * pauses repairs, voids open quotes and revokes proofs; keeps quotes, approvals, payments,
     * receipts and the audit trail. Final deletion waits for the retention decision (D-12).
     */
    public function close(Request $request): JsonResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);
        $ws = DB::selectOne('SELECT name FROM workspaces WHERE id = ?', [TenantContext::workspaceId()]);
        if (TenantContext::role() !== 'owner') abort(403, 'Only the owner can close a workspace.');
        if (!$ws || $data['confirm_name'] !== $ws->name) abort(422, 'Type the workspace name exactly to confirm.');
        $at = DB::selectOne('SELECT close_workspace(?) AS t', [$data['reason']])->t;
        Audit::record('workspace.closed', 'allowed', 'workspace', TenantContext::workspaceId(), ['reason' => $data['reason']]);
        return response()->json(['closed_at' => $at, 'reopen_until' => \Carbon\Carbon::parse($at)->addDays(30)->toIso8601String(),
            'note' => 'Monitoring stopped and provider credentials were destroyed. Financial and audit records are kept.']);
    }

    public function reopen(string $workspace): JsonResponse
    {
        try {
            DB::select('SELECT reopen_workspace(?)', [$workspace]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'only the owner can reopen')) abort(404);
            throw $e;
        }
        TenantContext::apply(TenantContext::userId(), $workspace);
        Audit::record('workspace.reopened', 'allowed', 'workspace', $workspace);
        return response()->json(['reopened' => true, 'note' => 'Monitoring restarts. Reconnect providers and re-verify ownership; old quotes stay cancelled.']);
    }
}
