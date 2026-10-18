<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Audit;
use App\Support\EmailVerification;
use App\Support\TenantContext;
use App\Support\Tokens;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** ACC-001 signup ("For myself" / "For my clients"), login (with MFA challenge), logout. */
class AuthController
{
    /** bcrypt of a random string: keeps login timing equal for unknown emails. */
    private const DUMMY_HASH = '$2y$12$7Qm9QbZ8u4nH0zq2c6yZ6eQe7qVv3P2kH8l0hGJvJmW6p0sP3fW8C';

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', Password::min(12)],
            'account_type' => ['required', 'in:solo,agency'],
            'workspace_name' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        try {
            $id = DB::selectOne('SELECT auth_register(?, ?) AS id', [$data['email'], Hash::make($data['password'])])->id;
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['email' => 'An account with this email already exists. Sign in instead.']);
            }
            throw $e;
        }

        TenantContext::apply($id, null);
        $user = User::findOrFail($id);
        $ws = DB::selectOne('SELECT create_workspace(?, ?::workspace_kind) AS id', [$data['workspace_name'], $data['account_type']])->id;
        TenantContext::apply($id, $ws);
        Audit::record('user.registered', 'allowed', 'user', $id);
        EmailVerification::send($user);

        return response()->json([
            'token' => Tokens::issue($user),
            'user' => ['id' => $id, 'email' => $user->email, 'email_verified' => false, 'mfa_enabled' => false],
            'workspace_id' => $ws,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $row = DB::selectOne('SELECT * FROM auth_lookup(?)', [$data['email']]);
        $ok = Hash::check($data['password'], $row->password_hash ?? self::DUMMY_HASH);
        if (!$row || !$ok) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }

        TenantContext::apply($row->id, null);
        $user = User::findOrFail($row->id);

        if ($row->mfa_enabled) {
            // Short-lived token that can ONLY complete the MFA step (EnsureFullAccess blocks everything else).
            $challenge = Tokens::issue($user, ['mfa:challenge'], now()->addMinutes(Tokens::MFA_CHALLENGE_MINUTES));
            Audit::record('user.login.password_ok', 'allowed', 'user', $user->id, ['mfa' => 'required']);
            return response()->json(['mfa_required' => true, 'challenge_token' => $challenge]);
        }

        Audit::record('user.login', 'allowed', 'user', $user->id);
        return response()->json(['mfa_required' => false, 'token' => Tokens::issue($user)]);
    }

    /**
     * Forgotten password. Same answer whether or not the account exists (no enumeration); at most
     * 3 links per hour per account; only the newest link works; only its SHA-256 is stored.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $token = bin2hex(random_bytes(32));
        $minutes = (int) config('upfixy.password_reset_minutes', 30);
        $uid = DB::selectOne('SELECT issue_password_reset(?, ?, ?) AS u', [$data['email'], hash('sha256', $token), $minutes])->u;
        if ($uid) {
            Mail::to($data['email'])->send(new \App\Mail\SecurityNoticeMail('reset', [
                'link' => config('upfixy.frontend_url') . '/reset-password?token=' . $token, 'minutes' => $minutes]));
            TenantContext::apply($uid, null);
            Audit::record('user.password_reset_requested', 'allowed', 'user', $uid);
            TenantContext::apply(null, null);
        }
        return response()->json(['message' => 'If an account uses this email, we sent a link to reset the password.'], 202);
    }

    /** One-time link + new password. Signs the account out everywhere; MFA is still required at the next sign-in. */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);
        $uid = DB::selectOne('SELECT consume_password_reset(?, ?) AS u', [hash('sha256', $data['token']), Hash::make($data['password'])])->u;
        if (!$uid) {
            return response()->json(['message' => 'This link has expired or was already used. Ask for a new one.'], 422);
        }
        TenantContext::apply($uid, null);
        Audit::record('user.password_reset', 'allowed', 'user', $uid);
        $email = DB::selectOne('SELECT user_email(?) AS e', [$uid])->e;
        Mail::to($email)->send(new \App\Mail\SecurityNoticeMail('changed', ['at' => now()->utc()->format('Y-m-d H:i')]));
        return response()->json(['message' => 'Your password was changed. Sign in with the new password.']);
    }

    /** Email link target. No session needed: the link itself is the credential. */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $uid = EmailVerification::confirm($data['token']);
        if (!$uid) {
            return response()->json(['message' => 'This link has expired or was already used. Sign in and request a new one.'], 422);
        }
        TenantContext::apply($uid, null);
        Audit::record('user.email_verified', 'allowed', 'user', $uid);
        return response()->json(['email_verified' => true]);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if ($request->user()->email_verified_at) {
            return response()->json(['message' => 'Your email is already confirmed.'], 409);
        }
        EmailVerification::send($request->user());
        return response()->json(['message' => 'We sent a new confirmation link.'], 202);
    }

    /** Second login step: swap a challenge token + valid code for a full session. */
    public function mfaLogin(Request $request, \App\Support\Mfa $mfa): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        $challenge = $user->currentAccessToken();
        if (!$challenge->can('mfa:challenge') || $challenge->can('*')) {
            abort(403);
        }
        if (!$mfa->verify($data['code'])) {
            Audit::record('user.login.mfa', 'denied', 'user', $user->id);
            throw ValidationException::withMessages(['code' => 'That code is not valid. Codes change every 30 seconds.']);
        }
        DB::delete('DELETE FROM personal_access_tokens WHERE id = ?', [$challenge->getKey()]);
        Audit::record('user.login', 'allowed', 'user', $user->id, ['mfa' => true]);
        return response()->json(['token' => Tokens::issue($user, ['*'], null, mfaVerified: true)]);
    }

    public function me(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'id' => $u->id, 'email' => $u->email,
            'email_verified' => $u->email_verified_at !== null,
            'mfa_enabled' => $u->hasMfa(),
            'mfa_fresh' => (bool) $u->currentAccessToken()?->mfaFresh(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        DB::delete('DELETE FROM personal_access_tokens WHERE id = ?', [$request->user()->currentAccessToken()->getKey()]);
        return response()->json(null, 204);
    }

    /** Sign out everywhere: every token for this user. */
    public function logoutAll(Request $request): JsonResponse
    {
        DB::delete('DELETE FROM personal_access_tokens WHERE tokenable_id = ?', [$request->user()->id]);
        Audit::record('user.tokens_revoked', 'allowed', 'user', $request->user()->id);
        return response()->json(null, 204);
    }
}
