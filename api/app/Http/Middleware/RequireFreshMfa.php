<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up MFA (ACC-002, §16): sensitive actions need MFA enabled and a code
 * entered within the last 15 minutes on this session.
 */
class RequireFreshMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($reason = self::missing($request)) {
            Audit::record('mfa.step_up', 'denied', null, null, ['path' => $request->path(), 'reason' => $reason]);
            return self::response($reason);
        }
        return $next($request);
    }

    public static function missing(Request $request): ?string
    {
        $user = $request->user();
        if (!$user->hasMfa()) return 'mfa_setup_required';
        if (!$user->currentAccessToken()?->mfaFresh(config('upfixy.mfa_fresh_minutes'))) return 'mfa_step_up_required';
        return null;
    }

    public static function response(string $reason): Response
    {
        return response()->json([
            'code' => $reason,
            'message' => $reason === 'mfa_setup_required'
                ? 'Turn on two-step verification in Security settings before doing this.'
                : 'Enter your authentication code to confirm this action.',
        ], 403);
    }
}
