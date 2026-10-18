<?php

namespace App\Http\Controllers\Api;

use App\Mail\InvitationMail;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * ACC-005 invitations. Replaces "add an existing user by email", which revealed
 * whether an email had an account. An invitation never reveals that.
 */
class InvitationController
{
    private const ROLE_LABELS = [
        'admin' => 'an admin', 'technician' => 'a technician', 'billing' => 'billing contact',
        'client_owner' => 'a client owner', 'client_staff' => 'client staff',
    ];

    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::select(
            'SELECT id, email, role, client_id, expires_at, accepted_at, revoked_at, created_at
               FROM invitations ORDER BY created_at DESC')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'role' => ['required', 'in:admin,technician,billing,client_owner,client_staff'],
            'client_id' => ['nullable', 'uuid', 'required_if:role,client_owner,client_staff'],
        ]);
        if (!$request->user()->email_verified_at) {
            abort(403, 'Confirm your email address before inviting people.');
        }
        $token = bin2hex(random_bytes(32));
        $expires = now()->addDays(config('upfixy.invitation_days'));
        try {
            $id = DB::selectOne(
                'INSERT INTO invitations(workspace_id, email, role, client_id, token_hash, invited_by, expires_at)
                 VALUES (?, ?, ?::member_role, ?, ?, ?, ?) RETURNING id',
                [TenantContext::workspaceId(), strtolower($data['email']), $data['role'], $data['client_id'] ?? null,
                    hash('sha256', $token), TenantContext::userId(), $expires])->id;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                return response()->json(['message' => 'An invitation to this email is already open. Revoke it to send a new one.'], 409);
            }
            throw $e;
        }
        $ws = DB::selectOne('SELECT name FROM workspaces WHERE id = ?', [TenantContext::workspaceId()])->name;
        Mail::to($data['email'])->send(new InvitationMail(
            config('upfixy.frontend_url') . '/accept-invite?token=' . $token,
            $ws, self::ROLE_LABELS[$data['role']], $expires->toFormattedDateString()));
        Audit::record('invitation.created', 'allowed', 'invitation', $id, ['role' => $data['role']]);
        return response()->json(['id' => $id, 'expires_at' => $expires->toIso8601String()], 201);
    }

    public function revoke(string $invitation): JsonResponse
    {
        $n = DB::update('UPDATE invitations SET revoked_at = now() WHERE id = ? AND revoked_at IS NULL AND accepted_at IS NULL', [$invitation]);
        if ($n === 0) abort(404);
        Audit::record('invitation.revoked', 'allowed', 'invitation', $invitation);
        return response()->json(null, 204);
    }

    /** No workspace header: the invitation decides the workspace. */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/']]);
        try {
            $ws = DB::selectOne('SELECT accept_invitation(?) AS ws', [hash('sha256', $data['token'])])->ws;
        } catch (QueryException $e) {
            $msg = $e->errorInfo[2] ?? '';
            return match (true) {
                str_contains($msg, 'not valid') => response()->json(['message' => 'This invitation has expired, was revoked or was already used. Ask for a new one.'], 422),
                str_contains($msg, 'verify your email') => response()->json(['message' => 'Confirm your email address first, then open the invitation link again.', 'code' => 'email_unverified'], 403),
                str_contains($msg, 'different email') => response()->json(['message' => 'This invitation was sent to a different email address. Sign in with that address to accept it.'], 403),
                str_contains($msg, 'already a member') => response()->json(['message' => "You're already a member of this workspace."], 409),
                default => throw $e,
            };
        }
        TenantContext::apply(TenantContext::userId(), $ws);
        Audit::record('invitation.accepted', 'allowed', 'workspace', $ws);
        return response()->json(['workspace_id' => $ws]);
    }
}
