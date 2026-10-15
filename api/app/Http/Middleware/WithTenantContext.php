<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs each API request in one transaction with the RLS context set.
 *
 * - Commits only when the response is a success (< 400). Laravel converts
 *   controller exceptions into responses inside the middleware chain, so the
 *   status code, not a thrown exception, decides commit vs rollback. A request
 *   that fails halfway therefore never leaves partial writes behind.
 * - Workspace comes from X-Workspace-Id; a workspace the caller isn't an active
 *   member of is answered exactly like a missing one (404).
 * - Every 404 is audited as a denied access after the rollback (AC-ACC-003.1).
 */
class WithTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $ws = $request->header('X-Workspace-Id');
        if ($ws !== null && !Str::isUuid($ws)) {
            return response()->json(['message' => 'Workspace not found.'], 404);
        }
        $userId = $request->user()?->getAuthIdentifier();
        Audit::discardDeferred();

        DB::beginTransaction();
        try {
            TenantContext::apply($userId, $ws);
            $response = ($ws !== null && TenantContext::role() === null)
                ? response()->json(['message' => 'Workspace not found.'], 404)
                : $next($request);

            if ($response->getStatusCode() >= 400) {
                DB::rollBack();
                if ($response->getStatusCode() === 404) {
                    Audit::record('resource.not_found', 'denied', null, null, [
                        'method' => $request->method(),
                        'path' => $request->path(),
                    ]);
                }
            } else {
                DB::commit();
            }
            return $response;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            try {
                Audit::flushDeferred();
            } finally {
                TenantContext::clear();
            }
        }
    }
}
