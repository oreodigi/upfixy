<?php

use App\Http\Middleware\RequireWorkspace;
use App\Http\Middleware\WithTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => WithTenantContext::class,
            'workspace' => RequireWorkspace::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        // Database guards (RLS, triggers) are the last line of defence. Map their
        // errors to plain answers without exposing SQL.
        $exceptions->render(function (QueryException $e, Request $r) {
            $state = $e->errorInfo[0] ?? $e->getCode();
            $msg = $e->errorInfo[2] ?? '';
            return match (true) {
                $state === '40001' => response()->json(['message' => 'This item was changed by someone else. Reload and try again.'], 409),
                $state === '42501' && str_contains($msg, 'authorised payer') =>
                    response()->json(['message' => 'Only the client, or the agency where the client contract allows it, can approve this work.'], 403),
                $state === '42501' => response()->json(['message' => "You don't have permission to do that."], 403),
                $state === '23514' && str_contains($msg, 'no valid approval') =>
                    response()->json(['message' => 'This work needs a current customer approval (and verified payment if chargeable) before it can start.'], 422),
                $state === '23514' => response()->json(['message' => "That change isn't allowed."], 422),
                $state === '23503' => response()->json(['message' => 'A linked item was not found.'], 422),
                default => null,
            };
        });
    })->create();
