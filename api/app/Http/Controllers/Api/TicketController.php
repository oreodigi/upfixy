<?php

namespace App\Http\Controllers\Api;

use App\Domain\TicketStates;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** SVC-001, BRD-003, §18.2. The database trigger is the final authority on transitions. */
class TicketController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::select(
            'SELECT t.id, t.title, t.state, t.version, t.assignee_id, t.website_id, w.display_name AS website, t.updated_at
               FROM tickets t JOIN websites w ON w.id = t.website_id ORDER BY t.updated_at DESC')]);
    }

    public function show(string $ticket): JsonResponse
    {
        $t = DB::selectOne('SELECT id, title, state, version, website_id, assignee_id FROM tickets WHERE id = ?', [$ticket]);
        if (!$t) abort(404);
        $t->allowed_next = TicketStates::TRANSITIONS[$t->state];
        $t->quote = QuoteController::forTicket($ticket);
        $t->events = DB::select('SELECT from_state, to_state, reason, actor_id, created_at FROM ticket_events WHERE ticket_id = ? ORDER BY id', [$ticket]);
        return response()->json($t);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
        ]);
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$data['website_id']])) abort(404);
        $id = DB::selectOne('INSERT INTO tickets(workspace_id, website_id, title) VALUES (?, ?, ?) RETURNING id',
            [TenantContext::workspaceId(), $data['website_id'], $data['title']])->id;
        DB::insert("INSERT INTO ticket_events(workspace_id, ticket_id, actor_id, to_state) VALUES (?, ?, ?, 'new')",
            [TenantContext::workspaceId(), $id, TenantContext::userId()]);
        Audit::record('ticket.created', 'allowed', 'ticket', $id);
        return response()->json(['id' => $id, 'state' => 'new', 'version' => 1], 201);
    }

    /** A board drag or the "Move to" control both call this; the client never sets state directly. */
    public function transition(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in(TicketStates::ALL)],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $current = DB::selectOne('SELECT state, version FROM tickets WHERE id = ?', [$ticket]);
        if (!$current) abort(404);
        if ($current->version !== $data['version']) {
            return response()->json(['message' => 'This ticket was changed by someone else. Reload and try again.',
                'current' => $current], 409);
        }
        if (!in_array(TenantContext::role(), ['owner', 'admin', 'technician'], true)) {
            Audit::record('ticket.transition', 'denied', 'ticket', $ticket, ['to' => $data['to']]);
            abort(403, 'Only staff can move tickets between stages.');
        }
        $check = TicketStates::check($current->state, $data['to']);
        if ($check !== null) {
            Audit::record('ticket.transition', 'denied', 'ticket', $ticket, ['from' => $current->state, 'to' => $data['to']]);
            return response()->json(['message' => $check], 422);
        }

        // Trigger re-checks the transition, the approval gate and the version (race-safe).
        $updated = DB::update('UPDATE tickets SET state = ?::ticket_state, version = version + 1 WHERE id = ? AND version = ?',
            [$data['to'], $ticket, $data['version']]);
        if ($updated === 0) {
            return response()->json(['message' => 'This ticket was changed by someone else. Reload and try again.'], 409);
        }
        DB::insert('INSERT INTO ticket_events(workspace_id, ticket_id, actor_id, from_state, to_state, reason) VALUES (?, ?, ?, ?::ticket_state, ?::ticket_state, ?)',
            [TenantContext::workspaceId(), $ticket, TenantContext::userId(), $current->state, $data['to'], $data['reason'] ?? null]);
        Audit::record('ticket.transition', 'allowed', 'ticket', $ticket, ['from' => $current->state, 'to' => $data['to']]);

        return response()->json(['id' => $ticket, 'state' => $data['to'], 'version' => $data['version'] + 1]);
    }
}
