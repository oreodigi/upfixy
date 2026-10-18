<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks MFA-challenge tokens (and any limited token) from normal routes. */
class EnsureFullAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->tokenCan('*')) {
            return response()->json(['message' => 'Enter your authentication code to finish signing in.', 'code' => 'mfa_challenge_pending'], 403);
        }
        return $next($request);
    }
}
