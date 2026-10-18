<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\RequireFreshMfa;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** SVC-002/003: allowlisted actions, immutable quotes, fingerprint-bound approvals. */
class QuoteController
{
    public function catalog(): JsonResponse
    {
        return response()->json(['data' => DB::select('SELECT code, label, tier, price_paise FROM action_catalog WHERE active ORDER BY price_paise NULLS LAST, code')]);
    }

    public function store(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate([
            'monitor_target_id' => ['required', 'uuid'],
            'action_code' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:1000'],
            'amount_paise' => ['nullable', 'integer', 'min:1', 'max:100000000'], // custom work only; ignored otherwise
            'params' => ['nullable', 'array'],
        ]);
        if (!DB::selectOne('SELECT 1 AS x FROM tickets WHERE id = ?', [$ticket])) abort(404);
        if (!in_array(TenantContext::role(), ['owner', 'admin', 'technician'], true)) {
            Audit::record('quote.create', 'denied', 'ticket', $ticket);
            abort(403, 'Only staff can issue quotes.');
        }
        $q = DB::selectOne(
            "INSERT INTO quotes(workspace_id, ticket_id, monitor_target_id, action_code, description, amount_paise, params, issued_by, fingerprint, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, repeat('0', 64), NULL) RETURNING id",
            [TenantContext::workspaceId(), $ticket, $data['monitor_target_id'], $data['action_code'], $data['description'],
                $data['amount_paise'] ?? 0, json_encode((object) ($data['params'] ?? [])), TenantContext::userId()]);
        Audit::record('quote.issued', 'allowed', 'quote', $q->id, ['ticket' => $ticket]);
        return response()->json(self::find($q->id), 201);
    }

    public function void(Request $request, string $quote): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $n = DB::update('UPDATE quotes SET voided_at = now(), void_reason = ? WHERE id = ? AND voided_at IS NULL', [$data['reason'], $quote]);
        if ($n === 0) abort(404);
        Audit::record('quote.voided', 'allowed', 'quote', $quote);
        return response()->json(null, 204);
    }

    /** The payer approves exactly the version they saw, identified by its fingerprint. */
    public function approve(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'uuid'],
            'fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        $q = DB::selectOne('SELECT id, amount_paise FROM quotes WHERE id = ? AND ticket_id = ?', [$data['quote_id'], $ticket]);
        if (!$q) abort(404);
        if (!$request->user()->email_verified_at) {
            Audit::record('approval.create', 'denied', 'ticket', $ticket, ['reason' => 'email_unverified']);
            abort(403, 'Verify your email address before approving work.');
        }
        // Chargeable approvals are high-impact: step-up MFA (§16).
        if ((int) $q->amount_paise > 0 && ($reason = RequireFreshMfa::missing($request))) {
            Audit::record('approval.create', 'denied', 'ticket', $ticket, ['reason' => $reason]);
            return RequireFreshMfa::response($reason);
        }
        $id = DB::selectOne(
            "INSERT INTO approvals(workspace_id, ticket_id, quote_id, fingerprint, approved_by, amount_paise, action_fingerprint, expires_at)
             VALUES (?, ?, ?, ?, ?, 0, 'set-by-database', now() + interval '1 day') RETURNING id",
            [TenantContext::workspaceId(), $ticket, $data['quote_id'], $data['fingerprint'], TenantContext::userId()])->id;
        Audit::record('approval.created', 'allowed', 'approval', $id, ['quote' => $data['quote_id'], 'amount_paise' => (int) $q->amount_paise]);
        return response()->json(['id' => $id], 201);
    }

    /** Current quote for a ticket as the payer sees it, with what is still needed before work can start. */
    public static function forTicket(string $ticket): ?array
    {
        $q = DB::selectOne('SELECT id FROM quotes WHERE ticket_id = ? AND voided_at IS NULL ORDER BY issued_at DESC LIMIT 1', [$ticket]);
        return $q ? self::find($q->id) : null;
    }

    private static function find(string $id): array
    {
        $q = DB::selectOne(
            "SELECT q.id, q.ticket_id, q.action_code, c.label AS action_label, q.description, q.amount_paise, q.currency,
                    q.params, q.issued_at, q.expires_at, q.fingerprint, q.voided_at,
                    t.id AS target_id, t.scheme, t.host, t.port, t.path,
                    site_private_access(q.workspace_id, q.monitor_target_id, 'repair') AS ownership_verified,
                    (SELECT a.id FROM approvals a WHERE a.quote_id = q.id AND a.voided_at IS NULL LIMIT 1) AS approval_id,
                    (SELECT a.payment_verified FROM approvals a WHERE a.quote_id = q.id AND a.voided_at IS NULL LIMIT 1) AS payment_verified
               FROM quotes q JOIN action_catalog c ON c.code = q.action_code JOIN monitor_targets t ON t.id = q.monitor_target_id
              WHERE q.id = ?", [$id]);
        return [
            'id' => $q->id, 'ticket_id' => $q->ticket_id,
            'action' => ['code' => $q->action_code, 'label' => $q->action_label],
            'target' => ['id' => $q->target_id, 'host' => $q->host, 'url' => "{$q->scheme}://{$q->host}{$q->path}"],
            'description' => $q->description, 'amount_paise' => (int) $q->amount_paise, 'currency' => $q->currency,
            'params' => json_decode($q->params, true), 'issued_at' => $q->issued_at, 'expires_at' => $q->expires_at,
            'fingerprint' => $q->fingerprint, 'voided' => $q->voided_at !== null,
            'ownership_verified' => (bool) $q->ownership_verified,
            'approved' => $q->approval_id !== null,
            'payment_required' => (int) $q->amount_paise > 0 && !$q->payment_verified,
        ];
    }
}
