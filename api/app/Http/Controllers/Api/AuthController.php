<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** ACC-001 signup ("For myself" / "For my clients"), login, logout. */
class AuthController
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', Password::min(12)],
            'account_type' => ['required', 'in:solo,agency'],
            'workspace_name' => ['required', 'string', 'min:1', 'max:120'],
        ]);
        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists. Log in instead.']);
        }

        $user = User::create(['email' => $data['email'], 'password_hash' => Hash::make($data['password'])]);
        TenantContext::apply($user->id, null);
        $ws = DB::selectOne('SELECT create_workspace(?, ?::workspace_kind) AS id', [$data['workspace_name'], $data['account_type']])->id;
        TenantContext::apply($user->id, $ws);
        Audit::record('user.registered', 'allowed', 'user', $user->id);

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => ['id' => $user->id, 'email' => $user->email, 'email_verified' => false],
            'workspace_id' => $ws,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['email' => 'Email or password is incorrect.']);
        }
        TenantContext::apply($user->id, null);
        Audit::record('user.login', 'allowed', 'user', $user->id);

        return response()->json(['token' => $user->createToken('web')->plainTextToken]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(null, 204);
    }
}
