<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** ACC-005: team list, role change, suspend (revoke access) and reinstate. */
class MemberController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::select(
            'SELECT user_id, email, role, suspended_at, email_verified, mfa_enabled, created_at
               FROM workspace_members WHERE workspace_id = ? ORDER BY suspended_at NULLS FIRST, email',
            [TenantContext::workspaceId()])]);
    }

    public function update(Request $request, string $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'in:owner,admin,technician,billing,client_owner,client_staff'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($data === []) abort(422, 'Nothing to change.');
        if (!in_array(TenantContext::role(), ['owner', 'admin'], true)) {
            Audit::record('member.update', 'denied', 'user', $user);
            abort(403, 'Only owners and admins can change team members.');
        }
        $sets = [];
        $args = [];
        if (isset($data['role'])) { $sets[] = 'role = ?::member_role'; $args[] = $data['role']; }
        if (isset($data['active'])) { $sets[] = $data['active'] ? 'suspended_at = NULL' : 'suspended_at = coalesce(suspended_at, now())'; }
        $args[] = TenantContext::workspaceId();
        $args[] = $user;
        $n = DB::update('UPDATE memberships SET ' . implode(', ', $sets) . ' WHERE workspace_id = ? AND user_id = ?', $args);
        if ($n === 0) abort(404);
        Audit::record(isset($data['active']) && !$data['active'] ? 'member.suspended' : 'member.updated', 'allowed', 'user', $user, $data);
        return response()->json(null, 204);
    }
}
