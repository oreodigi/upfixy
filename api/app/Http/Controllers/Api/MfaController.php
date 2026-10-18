<?php

namespace App\Http\Controllers\Api;

use App\Support\Audit;
use App\Support\Mfa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** ACC-002: TOTP enrolment, step-up and removal. */
class MfaController
{
    public function __construct(private Mfa $mfa) {}

    public function setup(Request $request): JsonResponse
    {
        if ($request->user()->hasMfa()) {
            return response()->json(['message' => 'Two-step verification is already on.'], 409);
        }
        return response()->json($this->mfa->begin($request->user()->email));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $codes = $this->mfa->confirm($data['code']);
        if ($codes === null) {
            Audit::record('mfa.enable', 'denied', 'user', $request->user()->id);
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }
        Mfa::markTokenFresh($request->user()->currentAccessToken()->getKey());
        Audit::record('mfa.enabled', 'allowed', 'user', $request->user()->id);
        return response()->json(['recovery_codes' => $codes]);
    }

    /** Step-up: confirms the user is present for the next 15 minutes on this session. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        if (!$this->mfa->verify($data['code'])) {
            Audit::record('mfa.step_up', 'denied', 'user', $request->user()->id);
            throw ValidationException::withMessages(['code' => 'That code is not valid. Codes change every 30 seconds.']);
        }
        Mfa::markTokenFresh($request->user()->currentAccessToken()->getKey());
        return response()->json(['mfa_fresh_until' => now()->addMinutes(config('upfixy.mfa_fresh_minutes'))->toIso8601String()]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        if (!$this->mfa->verify($data['code'])) {
            Audit::record('mfa.disable', 'denied', 'user', $request->user()->id);
            throw ValidationException::withMessages(['code' => 'That code is not valid.']);
        }
        $this->mfa->disable();
        Audit::record('mfa.disabled', 'allowed', 'user', $request->user()->id);
        return response()->json(null, 204);
    }
}
