<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DiagnosticController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\RepairController;
use App\Http\Controllers\Api\SavedViewController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\WebsiteController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| Middleware:
|   tenant     one DB transaction per request with RLS context (WithTenantContext)
|   full       blocks MFA-challenge tokens from everything except finishing sign-in
|   workspace  route needs an X-Workspace-Id the caller belongs to
|   mfa.fresh  step-up: MFA enabled and a code entered in the last 15 minutes
*/

// Public (no session).
Route::prefix('v1')->middleware('tenant')->group(function () {
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/verify-email', [AuthController::class, 'verifyEmail']);
    });
});

// MFA challenge token only: finish signing in.
Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'throttle:mfa'])->group(function () {
    Route::post('auth/mfa/login', [AuthController::class, 'mfaLogin']);
});

Route::prefix('v1')->middleware(['auth:sanctum', 'tenant', 'full'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::post('auth/verify-email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:3,10');

    Route::middleware('throttle:mfa')->group(function () {
        Route::post('mfa/setup', [MfaController::class, 'setup']);
        Route::post('mfa/confirm', [MfaController::class, 'confirm']);
        Route::post('mfa/verify', [MfaController::class, 'verify']);
        Route::post('mfa/disable', [MfaController::class, 'disable']);
    });

    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::post('workspaces', [WorkspaceController::class, 'store']);
    Route::post('invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');

    Route::middleware('workspace')->group(function () {
        Route::get('members', [MemberController::class, 'index']);
        Route::patch('members/{user}', [MemberController::class, 'update'])->whereUuid('user')->middleware('mfa.fresh');

        Route::get('invitations', [InvitationController::class, 'index']);
        Route::post('invitations', [InvitationController::class, 'store'])->middleware(['mfa.fresh', 'throttle:20,60']);
        Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])->whereUuid('invitation')->middleware('mfa.fresh');

        Route::get('clients', [ClientController::class, 'index']);
        Route::post('clients', [ClientController::class, 'store']);
        Route::patch('clients/{client}', [ClientController::class, 'update'])->whereUuid('client');
        Route::get('clients/{client}', [BoardController::class, 'client'])->whereUuid('client');
        Route::post('clients/{client}/assignments', [ClientController::class, 'assign'])->whereUuid('client');
        Route::delete('clients/{client}/assignments/{user}', [ClientController::class, 'unassign'])->whereUuid(['client', 'user']);

        Route::get('websites', [WebsiteController::class, 'index']);
        Route::post('websites', [WebsiteController::class, 'store'])->middleware('throttle:60,1');
        Route::get('websites/{website}', [WebsiteController::class, 'show'])->whereUuid('website');
        Route::get('websites/{website}/history', [WebsiteController::class, 'history'])->whereUuid('website');
        Route::post('websites/{website}/recheck', [WebsiteController::class, 'recheck'])->whereUuid('website')->middleware('throttle:30,1');
        Route::post('websites/{website}/diagnose', [DiagnosticController::class, 'start'])->whereUuid('website')->middleware('throttle:10,1');
        Route::get('websites/{website}/diagnostics', [DiagnosticController::class, 'index'])->whereUuid('website');
        Route::get('diagnostics/{run}', [DiagnosticController::class, 'show'])->whereUuid('run');

        // M7 controlled repairs (FIX-003..005)
        Route::get('websites/{website}/dns-connections', [RepairController::class, 'connections'])->whereUuid('website');
        Route::post('websites/{website}/dns-connections', [RepairController::class, 'connect'])->whereUuid('website')->middleware('mfa.fresh');
        Route::post('dns-connections/{connection}/rotate', [RepairController::class, 'rotate'])->whereUuid('connection')->middleware('mfa.fresh');
        Route::post('dns-connections/{connection}/revoke', [RepairController::class, 'revoke'])->whereUuid('connection')->middleware('mfa.fresh');
        Route::post('tickets/{ticket}/repair-drafts', [RepairController::class, 'draft'])->whereUuid('ticket')->middleware(['mfa.fresh', 'throttle:20,1']);
        Route::get('tickets/{ticket}/repairs', [RepairController::class, 'forTicket'])->whereUuid('ticket');
        Route::get('repair-drafts/{draft}', [RepairController::class, 'showDraft'])->whereUuid('draft');
        Route::post('repair-drafts/{draft}/quote', [RepairController::class, 'quote'])->whereUuid('draft');
        Route::post('approvals/{approval}/repairs', [RepairController::class, 'execute'])->whereUuid('approval')->middleware('mfa.fresh');
        Route::get('repair-attempts/{attempt}', [RepairController::class, 'attempt'])->whereUuid('attempt');
        Route::get('repair-controls', [RepairController::class, 'controls']);
        Route::post('repair-controls', [RepairController::class, 'setControls']);

        Route::get('websites/{website}/claims', [ClaimController::class, 'index'])->whereUuid('website');
        Route::post('websites/{website}/claims', [ClaimController::class, 'store'])->whereUuid('website')->middleware('throttle:20,1');
        Route::post('claims/{claim}/check', [ClaimController::class, 'check'])->whereUuid('claim')->middleware('throttle:10,1');
        Route::post('claims/{claim}/revoke', [ClaimController::class, 'revoke'])->whereUuid('claim')->middleware('mfa.fresh');

        Route::get('boards/websites', [BoardController::class, 'websites']);
        Route::get('boards/clients', [BoardController::class, 'clients']);
        Route::get('overview', [BoardController::class, 'overview']);

        Route::get('incidents', [IncidentController::class, 'index']);
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->whereUuid('incident');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->whereUuid('incident');
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification');
        Route::get('notification-preferences', [NotificationController::class, 'prefs']);
        Route::put('notification-preferences', [NotificationController::class, 'updatePrefs']);

        Route::get('views', [SavedViewController::class, 'index']);
        Route::post('views', [SavedViewController::class, 'store'])->middleware('throttle:30,1');
        Route::patch('views/{view}', [SavedViewController::class, 'update'])->whereUuid('view');
        Route::delete('views/{view}', [SavedViewController::class, 'destroy'])->whereUuid('view');

        Route::get('actions', [QuoteController::class, 'catalog']);
        Route::get('tickets', [TicketController::class, 'index']);
        Route::post('tickets', [TicketController::class, 'store']);
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->whereUuid('ticket');
        Route::post('tickets/{ticket}/transition', [TicketController::class, 'transition'])->whereUuid('ticket');
        Route::get('tickets/{ticket}/assignable', [TicketController::class, 'assignable'])->whereUuid('ticket');
        Route::patch('tickets/{ticket}/assignee', [TicketController::class, 'assign'])->whereUuid('ticket');
        Route::post('tickets/{ticket}/quotes', [QuoteController::class, 'store'])->whereUuid('ticket');
        Route::post('quotes/{quote}/void', [QuoteController::class, 'void'])->whereUuid('quote');
        Route::post('tickets/{ticket}/approvals', [QuoteController::class, 'approve'])->whereUuid('ticket');
    });
});
