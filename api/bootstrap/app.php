<?php

use App\Http\Middleware\EnsureFullAccess;
use App\Http\Middleware\RequireFreshMfa;
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
            'full' => EnsureFullAccess::class,
            'mfa.fresh' => RequireFreshMfa::class,
        ]);
        // API only: never redirect a guest to a login page; the handler answers 401 JSON.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        // Database guards (RLS, triggers, functions) are the last line of defence.
        // Known messages get a plain answer; nothing else exposes SQL.
        $exceptions->render(function (QueryException $e, Request $r) {
            $state = $e->errorInfo[0] ?? (string) $e->getCode();
            $msg = $e->errorInfo[2] ?? '';
            $known = [
                'quote fingerprint mismatch' => [409, 'This quote changed after you opened it. Review the current quote and approve again.'],
                'quote not found' => [404, 'Not found.'],
                'this quote is no longer valid' => [409, 'This quote has expired or been replaced. Ask for a new quote.'],
                'ownership of this address is not verified' => [422, 'Verify ownership of this address (DNS or file check) before approving or starting work.'],
                'authorised payer' => [403, 'Only the client, or the agency where the client contract allows it, can approve this work.'],
                'awaiting a quote decision' => [422, 'Approvals are only taken while the request is in "Quote pending".'],
                'no valid approval' => [422, 'This work needs a current customer approval (and verified payment if chargeable) before it can start.'],
                'only an owner can change an owner' => [403, 'Only an owner can change another owner.'],
                'at least one owner' => [422, 'A workspace must keep at least one owner.'],
                'only the workspace owner can change payment authority' => [403, 'Only the workspace owner can change who pays for client work.'],
                'quotes can only be issued' => [422, 'Quotes can only be issued while a request is being investigated or waiting for a quote.'],
                "the quoted address must belong" => [422, "The quoted address must be one of this website's monitored addresses."],
                'action is not in the catalogue' => [422, 'That action is not in the service catalogue.'],
                'custom work needs a positive amount' => [422, 'Custom work needs an amount.'],
                'host is not monitored on this website' => [422, 'That address is not monitored on this website.'],
                'DNS verification needs a domain name' => [422, 'DNS verification needs a domain name, not an IP address. Use the file method instead.'],
                'not allowed to verify ownership' => [403, "You don't have permission to verify ownership of this website."],
                'only owners and admins can revoke' => [403, 'Only owners and admins can revoke ownership proof.'],
                'technicians can only take or drop their own' => [403, 'Technicians can only take or drop their own requests.'],
                'requests can only be assigned to active staff' => [422, 'Requests can only be assigned to active staff.'],
                'this technician is not assigned to the client' => [422, "That technician isn't assigned to this client."],
            ];
            foreach ($known as $needle => [$status, $text]) {
                if (str_contains($msg, $needle)) {
                    return response()->json(['message' => $text], $status);
                }
            }
            return match (true) {
                $state === '40001' => response()->json(['message' => 'This item was changed by someone else. Reload and try again.'], 409),
                $state === 'P0002' => response()->json(['message' => 'Not found.'], 404),
                $state === '42501' => response()->json(['message' => "You don't have permission to do that."], 403),
                $state === '23514' => response()->json(['message' => "That change isn't allowed."], 422),
                $state === '23503' => response()->json(['message' => 'A linked item was not found.'], 422),
                $state === '22P02' => response()->json(['message' => 'Invalid value.'], 422),
                default => null,
            };
        });
    })->create();
