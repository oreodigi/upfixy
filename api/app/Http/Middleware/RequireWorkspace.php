<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Workspace-scoped routes need a workspace the caller belongs to (checked in WithTenantContext). */
class RequireWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        if (TenantContext::workspaceId() === null) {
            return response()->json(['message' => 'Choose a workspace (X-Workspace-Id header).'], 400);
        }
        return $next($request);
    }
}
