<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\RequireFreshMfa;
use App\Repairs\SecretBox;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FIX-003..005 through the API. Nothing here talks to a DNS provider: the API
 * seals tokens it can never read, records requests, and shows outcomes. The
 * executor does the provider reads and the one allowlisted write.
 */
class RepairController
{
    private function sqlError(QueryException $e): never
    {
        $m = $e->errorInfo[2] ?? '';
        if (str_contains($m, 'not found')) abort(404);
        if (preg_match('/only owners|not allowed|only an owner/', $m)) abort(403, 'You are not allowed to do this.');
        if (str_contains($m, 'repair_attempts_one_active')) abort(409, 'Another automated change for this DNS record is already in progress.');
        if (str_contains($m, 'repair_attempts_approval_id_key')) abort(409, 'This approval has already been used. A new approval is needed.');
        if (str_contains($m, 'dns_connections_live')) abort(409, 'This zone is already connected for this website.');
        if (str_contains($m, 'automated changes can only be quoted')) abort(422, 'Automated changes can only be quoted from a provider-read preview.');
        if (str_contains($m, 'not for an automated DNS repair')) abort(422, 'This approval is not for an automated DNS repair.');
        throw $e;
    }

    // ----------------------------------------------------------- connections
    public function connections(string $website): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM websites WHERE id = ?', [$website])) abort(404);
        return response()->json(['data' => DB::select(
            'SELECT id, provider, zone_id, zone_name, secret_version, created_at, verified_at, verify_result, revoked_at
               FROM dns_connections WHERE website_id = ? ORDER BY created_at DESC', [$website])]);
    }

    /** Step-up MFA (route). The token is sealed before it touches the database and is never returned or logged. */
    public function connect(Request $request, string $website): JsonResponse
    {
        $data = $request->validate([
            'zone_id' => ['required', 'regex:/^[0-9a-f]{32}$/'],
            'zone_name' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'api_token' => ['required', 'string', 'min:20', 'max:200'],
        ]);
        $sealed = SecretBox::seal($data['api_token']);
        unset($data['api_token']);
        try {
            $id = DB::selectOne('SELECT create_dns_connection(?, ?, ?, ?) AS id', [$website, $data['zone_id'], $data['zone_name'], $sealed])->id;
        } catch (QueryException $e) {
            Audit::record('dns_connection.create', 'denied', 'website', $website);
            $this->sqlError($e);
        }
        Audit::record('dns_connection.created', 'allowed', 'dns_connection', $id, ['zone_id' => $data['zone_id']]);
        return response()->json(['id' => $id, 'note' => 'Stored sealed. Upfixy checks the token can see this zone before it is used.'], 201);
    }

    public function rotate(Request $request, string $connection): JsonResponse
    {
        $data = $request->validate(['api_token' => ['required', 'string', 'min:20', 'max:200']]);
        try {
            $v = DB::selectOne('SELECT rotate_dns_connection(?, ?) AS v', [$connection, SecretBox::seal($data['api_token'])])->v;
        } catch (QueryException $e) {
            $this->sqlError($e);
        }
        Audit::record('dns_connection.rotated', 'allowed', 'dns_connection', $connection, ['version' => $v]);
        return response()->json(['secret_version' => $v]);
    }

    public function revoke(string $connection): JsonResponse
    {
        try {
            DB::select('SELECT revoke_dns_connection(?)', [$connection]);
        } catch (QueryException $e) {
            $this->sqlError($e);
        }
        Audit::record('dns_connection.revoked', 'allowed', 'dns_connection', $connection);
        return response()->json(null, 204);
    }

    // ----------------------------------------------------------------- drafts
    public function draft(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate([
            'connection_id' => ['required', 'uuid'], 'monitor_target_id' => ['required', 'uuid'],
            'record_id' => ['required', 'regex:/^[0-9a-f]{32}$/'], 'proposed_content' => ['required', 'string', 'max:45'],
            'diagnostic_run_id' => ['nullable', 'uuid'],
        ]);
        try {
            $id = DB::selectOne('SELECT create_repair_draft(?, ?, ?, ?, ?, ?) AS id', [$ticket, $data['monitor_target_id'], $data['connection_id'],
                $data['record_id'], strtolower(trim($data['proposed_content'])), $data['diagnostic_run_id'] ?? null])->id;
        } catch (QueryException $e) {
            Audit::record('repair.draft', 'denied', 'ticket', $ticket);
            $this->sqlError($e);
        }
        Audit::record('repair.drafted', 'allowed', 'repair_draft', $id);
        return response()->json(['id' => $id, 'state' => 'reading'], 201);
    }

    public function showDraft(string $draft): JsonResponse
    {
        $d = DB::selectOne('SELECT id, ticket_id, state, refusal, plan, created_at, read_at FROM repair_drafts WHERE id = ?', [$draft]);
        if (!$d) abort(404);
        $d->plan = $d->plan ? json_decode($d->plan, true) : null;
        return response()->json($d);
    }

    /** Preview → quote. The quote's fingerprinted params ARE the executor-read plan (S-06 guard in the database). */
    public function quote(string $draft): JsonResponse
    {
        $d = DB::selectOne('SELECT * FROM repair_drafts WHERE id = ?', [$draft]);
        if (!$d) abort(404);
        if (!in_array(TenantContext::role(), ['owner', 'admin', 'technician'], true)) abort(403, 'Only staff can issue quotes.');
        if ($d->state !== 'ready') abort(422, $d->state === 'refused' ? "This change cannot be automated: {$d->refusal}" : 'The preview is still being prepared.');
        $p = json_decode($d->plan, true);
        $desc = sprintf('Change the %s record %s from %s to %s. TTL (%d) and proxy setting (%s) stay the same. '
            . 'Afterwards Upfixy checks public DNS and the website; if the website starts failing because of this change and the record still holds the new value, it is put back to %s.',
            $p['record_type'], $p['record_name'], $p['before']['content'], $p['after']['content'], $p['before']['ttl'],
            $p['before']['proxied'] ? 'on' : 'off', $p['before']['content']);
        try {
            $q = DB::transaction(function () use ($d, $desc) {
                $q = DB::selectOne(
                    "INSERT INTO quotes(workspace_id, ticket_id, monitor_target_id, action_code, description, amount_paise, params, issued_by, fingerprint, expires_at)
                     VALUES (?, ?, ?, 'dns.record_fix', ?, 0, ?::jsonb, ?, repeat('0', 64), now() + interval '2 days') RETURNING id",
                    [TenantContext::workspaceId(), $d->ticket_id, $d->monitor_target_id, $desc, $d->plan, TenantContext::userId()]);
                $tk = DB::selectOne('SELECT state::text AS state, version FROM tickets WHERE id = ?', [$d->ticket_id]);
                if ($tk->state !== 'quote_pending') {
                    DB::update("UPDATE tickets SET state = 'quote_pending', version = version + 1 WHERE id = ?", [$d->ticket_id]);
                    DB::insert('INSERT INTO ticket_events(workspace_id, ticket_id, actor_id, from_state, to_state, reason) VALUES (?, ?, ?, ?::ticket_state, ?, ?)',
                        [TenantContext::workspaceId(), $d->ticket_id, TenantContext::userId(), $tk->state, 'quote_pending', 'Automated DNS fix quoted']);
                }
                return $q;
            });
        } catch (QueryException $e) {
            $this->sqlError($e);
        }
        Audit::record('repair.quoted', 'allowed', 'quote', $q->id, ['draft' => $draft]);
        return response()->json(['quote_id' => $q->id], 201);
    }

    // --------------------------------------------------------------- attempts
    /** Step-up MFA (route). Idempotent per key. Gates are re-checked by the executor at every phase. */
    public function execute(Request $request, string $approval): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/']]);
        try {
            $id = DB::selectOne('SELECT request_repair(?, ?) AS id', [$approval, $data['idempotency_key']])->id;
        } catch (QueryException $e) {
            Audit::record('repair.request', 'denied', 'approval', $approval);
            $this->sqlError($e);
        }
        Audit::record('repair.requested', 'allowed', 'repair_attempt', $id);
        return response()->json(['id' => $id], 202);
    }

    public function attempt(string $attempt): JsonResponse
    {
        $a = DB::selectOne('SELECT id, ticket_id, state, state_reason, customer_message, plan, created_at, updated_at FROM repair_attempts WHERE id = ?', [$attempt]);
        if (!$a) abort(404);
        $staff = in_array(TenantContext::role(), ['owner', 'admin', 'technician'], true);
        $a->plan = json_decode($a->plan, true);
        // Customers see the plain outcome; staff also see every step and provider reference (AC-FIX-005.4).
        $a->events = DB::select('SELECT kind, ' . ($staff ? 'detail, ' : '') . 'at FROM repair_events WHERE attempt_id = ? ORDER BY id', [$attempt]);
        foreach ($a->events as $e) if (isset($e->detail)) $e->detail = json_decode($e->detail, true);
        if (!$staff) unset($a->state_reason);
        return response()->json($a);
    }

    public function forTicket(string $ticket): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM tickets WHERE id = ?', [$ticket])) abort(404);
        return response()->json([
            'drafts' => array_map(function ($d) { $d->plan = $d->plan ? json_decode($d->plan, true) : null; return $d; },
                DB::select('SELECT id, state, refusal, plan, created_at FROM repair_drafts WHERE ticket_id = ? ORDER BY created_at DESC', [$ticket])),
            'attempts' => DB::select('SELECT id, state, customer_message, created_at, updated_at FROM repair_attempts WHERE ticket_id = ? ORDER BY created_at DESC', [$ticket]),
        ]);
    }

    // --------------------------------------------------------------- controls
    public function controls(): JsonResponse
    {
        $rows = DB::select('SELECT workspace_id, paused, reason, changed_at FROM repair_controls');
        $g = collect($rows)->firstWhere('workspace_id', null);
        $w = collect($rows)->first(fn ($r) => $r->workspace_id !== null);
        return response()->json([
            'platform_enabled' => (bool) config('upfixy.repairs.enabled'),
            'global' => ['paused' => (bool) ($g->paused ?? true), 'reason' => $g->reason ?? null],
            'workspace' => ['paused' => (bool) ($w->paused ?? false), 'reason' => $w->reason ?? null, 'changed_at' => $w->changed_at ?? null],
        ]);
    }

    /** Pausing is immediate for owners/admins. Resuming needs the owner with fresh MFA. */
    public function setControls(Request $request): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        if (!$data['paused'] && ($why = RequireFreshMfa::missing($request))) return RequireFreshMfa::response($why);
        try {
            DB::select('SELECT set_workspace_repairs_paused(?, ?)', [$data['paused'], $data['reason']]);
        } catch (QueryException $e) {
            Audit::record('repairs.controls', 'denied');
            $this->sqlError($e);
        }
        Audit::record($data['paused'] ? 'repairs.paused' : 'repairs.resumed', 'allowed', 'workspace', TenantContext::workspaceId(), ['reason' => $data['reason']]);
        return $this->controls();
    }
}
