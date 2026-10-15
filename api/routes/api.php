<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\WebsiteController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// All routes run inside one transaction with RLS context (WithTenantContext).
// Workspace-scoped routes need the X-Workspace-Id header.
Route::prefix('v1')->middleware('tenant')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
    });
});

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::post('workspaces', [WorkspaceController::class, 'store']);

    Route::middleware('workspace')->group(function () {
        Route::post('members', [WorkspaceController::class, 'addMember']);

        Route::get('clients', [ClientController::class, 'index']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::post('clients/{client}/assignments', [ClientController::class, 'assign'])->whereUuid('client');

        Route::get('websites', [WebsiteController::class, 'index']);
        Route::post('websites', [WebsiteController::class, 'store'])->middleware('throttle:60,1');
        Route::get('websites/{website}', [WebsiteController::class, 'show'])->whereUuid('website');

        Route::get('boards/websites', [BoardController::class, 'websites']);
        Route::get('boards/clients', [BoardController::class, 'clients']);

        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereUuid('ticket');
        Route::post('tickets/{ticket}/transition', [TicketController::class, 'transition'])->whereUuid('ticket');
        Route::post('tickets/{ticket}/approvals', [TicketController::class, 'approve'])->whereUuid('ticket');
    });
});
