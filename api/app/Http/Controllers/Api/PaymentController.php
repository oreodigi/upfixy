<?php

namespace App\Http\Controllers\Api;

use App\Payments\HttpRazorpayClient;
use App\Payments\PaymentError;
use App\Payments\RazorpayClient;
use App\Payments\Signatures;
use App\Support\Audit;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * SVC-002…005, SCR-12/15. Razorpay TEST mode only until D-05/06/13/14/18/23 are signed:
 * live keys are refused here, by the client and by the database.
 */
class PaymentController
{
    public function __construct(private RazorpayClient $rzp) {}

    private function sqlError(QueryException $e): never
    {
        $m = $e->errorInfo[2] ?? $e->getMessage();
        if (str_contains($m, 'not found')) abort(404);
        if (preg_match('/only the payer|only owners/', $m)) abort(403, 'Only the payer can do this.');
        if (str_contains($m, 'live payments are not enabled')) abort(403, 'Live payments are disabled on this platform.');
        foreach (['this approval is no longer valid', 'nothing to pay', 'already paid', 'only verified payments', 'refund is more than'] as $k) {
            if (str_contains($m, $k)) abort(422, ucfirst($k) . '.');
        }
        throw $e;
    }

    private function secret(?object $m): string
    {
        if (!$m) abort(409, 'Payments are not set up for this workspace yet.');
        try {
            return Crypt::decryptString($m->key_secret_enc);
        } catch (\Throwable) {
            abort(409, 'The stored Razorpay keys cannot be read on this server. The owner needs to enter them again under Billing.');
        }
    }

    // ------------------------------------------------------------ merchant
    public function merchant(): JsonResponse
    {
        if (!in_array(TenantContext::role(), ['owner', 'admin', 'billing'], true)) abort(403);
        $m = DB::selectOne('SELECT legal_name, gstin, state_code, receipt_prefix, mode, razorpay_key_id, secret_version, updated_at FROM merchant_profiles');
        $c = DB::selectOne('SELECT live_allowed, reason FROM payment_controls');   // missing row = off
        return response()->json(['merchant' => $m, 'live_allowed' => HttpRazorpayClient::liveAllowed(),
            'live_note' => $c->reason ?? 'Live charging is off.', 'webhook_url' => rtrim((string) config('app.url'), '/') . '/api/v1/webhooks/razorpay/' . TenantContext::workspaceId()]);
    }

    /** Owner + fresh MFA (route). Secrets are encrypted before storage and never returned. */
    public function saveMerchant(Request $request): JsonResponse
    {
        if (TenantContext::role() !== 'owner') abort(403, 'Only the owner can set payment details.');
        $exists = DB::selectOne('SELECT 1 AS x FROM merchant_profiles') !== null;
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:2', 'max:160'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'state_code' => ['nullable', 'regex:/^[0-9]{2}$/'],
            'receipt_prefix' => ['nullable', 'regex:/^[A-Z0-9]{2,8}$/'],
            'razorpay_key_id' => ['required', 'regex:/^rzp_(test|live)_[A-Za-z0-9]{8,32}$/'],
            'key_secret' => [$exists ? 'nullable' : 'required', 'string', 'min:12', 'max:100'],
            'webhook_secret' => [$exists ? 'nullable' : 'required', 'string', 'min:12', 'max:100'],
        ]);
        if (str_starts_with($data['razorpay_key_id'], 'rzp_live_') && !HttpRazorpayClient::liveAllowed()) {
            return response()->json(['message' => 'Live Razorpay keys are refused: live charging is off until the founder signs off the payment model (D-05, D-06, D-14).',
                'errors' => ['razorpay_key_id' => ['Use a test key (rzp_test_…).']]], 422);
        }
        $mode = str_starts_with($data['razorpay_key_id'], 'rzp_live_') ? 'live' : 'test';
        $vals = ['legal_name' => $data['legal_name'], 'gstin' => $data['gstin'] ?? null, 'state_code' => $data['state_code'] ?? null,
            'receipt_prefix' => $data['receipt_prefix'] ?? 'UPX', 'mode' => $mode, 'razorpay_key_id' => $data['razorpay_key_id'], 'updated_by' => TenantContext::userId()];
        if (!empty($data['key_secret'])) $vals['key_secret_enc'] = Crypt::encryptString($data['key_secret']);
        if (!empty($data['webhook_secret'])) $vals['webhook_secret_enc'] = Crypt::encryptString($data['webhook_secret']);
        unset($data['key_secret'], $data['webhook_secret']);
        try {
            if ($exists) {
                DB::table('merchant_profiles')->where('workspace_id', TenantContext::workspaceId())->update($vals);
            } else {
                DB::table('merchant_profiles')->insert($vals + ['workspace_id' => TenantContext::workspaceId()]);
            }
        } catch (QueryException $e) {
            $this->sqlError($e);
        }
        Audit::record('merchant.saved', 'allowed', 'workspace', TenantContext::workspaceId(), ['mode' => $mode, 'key_id' => $data['razorpay_key_id']]);
        return $this->merchant();
    }

    // ------------------------------------------------------------ coverage
    public function coverage(): JsonResponse
    {
        return response()->json(['data' => DB::select(
            "SELECT c.id, c.client_id, cl.name AS client, c.website_id, w.display_name AS website, c.reference, c.scope, c.valid_from, c.valid_until,
                    c.revoked_at, (c.revoked_at IS NULL AND current_date BETWEEN c.valid_from AND c.valid_until) AS active
               FROM coverage_contracts c LEFT JOIN clients cl ON cl.id = c.client_id LEFT JOIN websites w ON w.id = c.website_id
              ORDER BY c.revoked_at IS NOT NULL, c.valid_until DESC")]);
    }

    public function addCoverage(Request $request): JsonResponse
    {
        if (!in_array(TenantContext::role(), ['owner', 'admin'], true)) abort(403);
        $data = $request->validate([
            'client_id' => ['nullable', 'uuid'], 'website_id' => ['nullable', 'uuid'],
            'reference' => ['required', 'string', 'max:120'], 'scope' => ['required', 'string', 'min:3', 'max:1000'],
            'valid_from' => ['required', 'date'], 'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
        ]);
        if ($data['website_id'] ?? null) {
            $w = DB::selectOne('SELECT client_id FROM websites WHERE id = ?', [$data['website_id']]);
            if (!$w) abort(422, 'Choose one of your websites.');
            $data['client_id'] = $w->client_id;
        } elseif (($data['client_id'] ?? null) && !DB::selectOne('SELECT 1 AS x FROM clients WHERE id = ?', [$data['client_id']])) {
            abort(422, 'Choose one of your clients.');
        }
        $id = DB::table('coverage_contracts')->insertGetId($data + ['workspace_id' => TenantContext::workspaceId(), 'created_by' => TenantContext::userId()]);
        Audit::record('coverage.created', 'allowed', 'coverage', $id, ['reference' => $data['reference']]);
        return response()->json(['id' => $id], 201);
    }

    public function revokeCoverage(string $coverage): JsonResponse
    {
        if (!in_array(TenantContext::role(), ['owner', 'admin'], true)) abort(403);
        $n = DB::update('UPDATE coverage_contracts SET revoked_at = now(), revoked_by = ? WHERE id = ? AND revoked_at IS NULL', [TenantContext::userId(), $coverage]);
        if ($n === 0) abort(404);
        Audit::record('coverage.revoked', 'allowed', 'coverage', $coverage);
        return response()->json(['revoked' => true]);
    }

    // -------------------------------------------------------------- orders
    /** Start (or resume) checkout for an approved, chargeable quote. Payer only. Idempotent. */
    public function checkout(Request $request, string $approval): JsonResponse
    {
        $data = $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:80']]);
        if (!DB::selectOne('SELECT 1 AS x FROM approvals WHERE id = ?', [$approval])) abort(404);
        $m = DB::selectOne('SELECT * FROM merchant_order_credentials()');
        if (!$m) abort(409, 'Payments are not set up for this workspace yet.');
        if ($m->mode === 'live' && !HttpRazorpayClient::liveAllowed()) abort(403, 'Live payments are disabled on this platform.');
        try {
            $oid = DB::selectOne('SELECT payment_open_order(?, ?, ?, ?, ?) AS id', [$approval, $data['idempotency_key'], $m->razorpay_key_id, $m->mode, $m->legal_name])->id;
        } catch (QueryException $e) {
            $this->sqlError($e);
        }
        $o = DB::selectOne('SELECT * FROM payment_orders WHERE id = ?', [$oid]);
        if ($o->status === 'paid_verified') abort(422, 'Already paid.');
        if ($o->provider_order_id === null) {
            try {
                $r = $this->rzp->createOrder($m->razorpay_key_id, $this->secret($m), (int) $o->amount_paise, $o->currency,
                    substr($o->id, 0, 36), ['upfixy_order' => $o->id, 'ticket' => $o->ticket_id, 'quote' => $o->quote_id]);
            } catch (PaymentError $e) {
                abort(502, $e->getMessage());
            }
            if (!preg_match('/^order_[A-Za-z0-9]{6,40}$/', $r['id'])) abort(502, 'Razorpay returned an unexpected order.');
            DB::select('SELECT payment_attach_provider_order(?, ?)', [$o->id, $r['id']]);
            $o->provider_order_id = $r['id'];
            Audit::record('payment.order_created', 'allowed', 'payment_order', $o->id, ['provider_order_id' => $r['id'], 'amount' => (int) $o->amount_paise]);
        }
        return response()->json(['order_id' => $o->id, 'status' => 'pending', 'mode' => $m->mode, 'checkout' => [
            'key' => $m->razorpay_key_id, 'order_id' => $o->provider_order_id, 'amount' => (int) $o->amount_paise, 'currency' => $o->currency,
            'name' => $m->legal_name, 'description' => 'Upfixy quote ' . substr($o->quote_id, 0, 8)]]);
    }

    /** The browser's success callback. Signature checked and recorded, but NOT proof of payment (AC-SVC-004.2). */
    public function clientConfirm(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'regex:/^pay_[A-Za-z0-9]{6,40}$/'],
            'razorpay_order_id' => ['required', 'regex:/^order_[A-Za-z0-9]{6,40}$/'],
            'razorpay_signature' => ['required', 'regex:/^[0-9a-f]{64}$/i'],
        ]);
        $o = DB::selectOne('SELECT id, provider_order_id, status FROM payment_orders WHERE id = ?', [$order]);
        if (!$o) abort(404);
        $m = DB::selectOne('SELECT * FROM merchant_order_credentials()');
        if ($o->provider_order_id !== $data['razorpay_order_id']
            || !Signatures::checkout($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'], $this->secret($m))) {
            Audit::record('payment.client_confirm', 'denied', 'payment_order', $order);
            abort(422, 'This payment confirmation could not be verified.');
        }
        DB::select('SELECT payment_client_reported(?)', [$order]);
        return response()->json(['status' => $o->status === 'paid_verified' ? 'paid_verified' : 'awaiting_confirmation',
            'note' => 'Thanks. Work starts once Razorpay confirms the payment to Upfixy directly (usually within a minute).']);
    }

    public function forTicket(string $ticket): JsonResponse
    {
        if (!DB::selectOne('SELECT 1 AS x FROM tickets WHERE id = ?', [$ticket])) abort(404);
        $orders = DB::select('SELECT id, quote_id, approval_id, amount_paise, currency, mode, merchant_name, status, status_reason, client_reported_at,
                                     refunded_paise, created_at, paid_at FROM payment_orders WHERE ticket_id = ? ORDER BY created_at DESC', [$ticket]);
        foreach ($orders as $o) {
            $o->receipt = DB::selectOne('SELECT id, number, issued_at, is_tax_invoice, tax_note FROM payment_receipts WHERE order_id = ?', [$o->id]);
            $o->refunds = DB::select('SELECT id, amount_paise, reason, status, requested_at, processed_at FROM refunds WHERE order_id = ? ORDER BY requested_at', [$o->id]);
        }
        return response()->json(['data' => $orders]);
    }

    /** SCR-15 for owner/admin/billing. */
    public function index(): JsonResponse
    {
        if (!in_array(TenantContext::role(), ['owner', 'admin', 'billing'], true)) abort(403);
        return response()->json([
            'orders' => DB::select("SELECT o.id, o.ticket_id, t.title, o.amount_paise, o.status, o.status_reason, o.mode, o.created_at, o.paid_at, o.refunded_paise,
                                           r.number AS receipt
                                      FROM payment_orders o JOIN tickets t ON t.id = o.ticket_id LEFT JOIN payment_receipts r ON r.order_id = o.id
                                     ORDER BY o.created_at DESC LIMIT 200"),
            'alerts' => DB::select('SELECT id, order_id, kind, detail, created_at FROM payment_alerts ORDER BY created_at DESC LIMIT 50'),
        ]);
    }

    public function receipt(string $receipt): JsonResponse
    {
        $r = DB::selectOne('SELECT * FROM payment_receipts WHERE id = ?', [$receipt]);
        if (!$r) abort(404);
        return response()->json($r);
    }

    /** Owner/billing; test mode only while live is off. D-13 (refund policy) decides when a refund is owed. */
    public function refund(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['amount_paise' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            $rid = DB::selectOne('SELECT payment_request_refund(?, ?, ?) AS id', [$order, $data['amount_paise'], $data['reason']])->id;
        } catch (QueryException $e) {
            $this->sqlError($e);
        }
        $o = DB::selectOne('SELECT provider_payment_id FROM payment_orders WHERE id = ?', [$order]);
        $m = DB::selectOne('SELECT * FROM merchant_order_credentials()');
        try {
            $r = $this->rzp->refund($m->razorpay_key_id, $this->secret($m), $o->provider_payment_id, (int) $data['amount_paise'],
                ['upfixy_refund' => $rid]);
            if (preg_match('/^rfnd_[A-Za-z0-9]{6,40}$/', $r['id'])) DB::select('SELECT payment_attach_refund(?, ?)', [$rid, $r['id']]);
        } catch (PaymentError $e) {
            Audit::record('payment.refund_requested', 'allowed', 'refund', $rid, ['provider' => 'unreachable']);
            return response()->json(['id' => $rid, 'status' => 'requested', 'note' => $e->getMessage() . ' The refund stays requested; retry later.'], 202);
        }
        Audit::record('payment.refund_requested', 'allowed', 'refund', $rid, ['amount' => (int) $data['amount_paise']]);
        return response()->json(['id' => $rid, 'status' => 'requested', 'note' => 'Refund requested. It is marked processed when Razorpay confirms it.'], 202);
    }
}
