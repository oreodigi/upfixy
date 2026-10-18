<?php

namespace Tests\Feature;

use App\Payments\RazorpayClient;
use App\Payments\Reconciler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeRazorpay;
use Tests\TestCase;

/**
 * M6 SVC-002…005 against a scripted Razorpay (test mode, no network, no real money).
 * AC-SVC-002.1, 003.3, 004.1, 004.2, 004.3, 005.1 (receipt consistency; GST pending D-14).
 */
class PaymentsTest extends TestCase
{
    private const KEY = 'rzp_test_AbCdEf123456';
    private const SECRET = 'test-key-secret-000001';
    private const HOOK = 'test-webhook-secret-01';

    private array $f;
    private FakeRazorpay $rzp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rzp = new FakeRazorpay();
        $this->app->instance(RazorpayClient::class, $this->rzp);
        $f = [];
        foreach (['olive', 'tina', 'carl', 'bea', 'bob'] as $n) $f[$n] = $this->makeUser($n);
        $f['ws'] = $this->makeWorkspace('Agency');
        $f['wsB'] = $this->makeWorkspace('Other');
        foreach ([['olive', 'owner'], ['tina', 'technician'], ['carl', 'client_owner'], ['bea', 'billing']] as [$u, $r]) $this->addMember($f['ws'], $f[$u], $r);
        $this->addMember($f['wsB'], $f['bob'], 'owner');
        $f['client'] = $this->makeClient($f['ws'], 'Mitra Traders');
        $this->assign($f['ws'], $f['client'], $f['tina']);
        $this->assign($f['ws'], $f['client'], $f['carl']);
        [$f['site'], $f['target']] = $this->makeSite($f['ws'], $f['client'], 'shop.mitra.example');
        foreach (['olive', 'tina', 'carl', 'bea', 'bob'] as $u) $this->enableMfa($f[$u]);
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $f['ticket'] = $this->makeTicket($f['ws'], $f['site'], 'investigating');
        $this->f = $f;
    }

    // ------------------------------------------------------------- helpers
    private function merchant(array $over = [])
    {
        return $this->api($this->f['olive'], $this->f['ws'], 'PUT', 'billing/merchant', $over + [
            'legal_name' => 'Mitra Web Services LLP', 'razorpay_key_id' => self::KEY, 'key_secret' => self::SECRET, 'webhook_secret' => self::HOOK,
        ], mfaFresh: true);
    }

    /** Quote → quote_pending → payer approval. Returns [quote id, approval id]. */
    private function approved(string $action = 'dns.record_fix', array $extra = []): array
    {
        $f = $this->f;
        $q = $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/quotes",
            ['monitor_target_id' => $f['target'], 'action_code' => $action, 'description' => 'Correct the www record', ...$extra])->assertCreated();
        $v = $this->owner()->selectOne('SELECT version, state FROM tickets WHERE id = ?', [$f['ticket']]);
        if ($v->state !== 'quote_pending') {
            $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/transition", ['to' => 'quote_pending', 'version' => $v->version])->assertOk();
        }
        $a = $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$f['ticket']}/approvals",
            ['quote_id' => $q->json('id'), 'fingerprint' => $q->json('fingerprint')], mfaFresh: true)->assertCreated();
        return [$q->json('id'), $a->json('id')];
    }

    private function checkout(string $approval, string $key = 'idem-key-000001', string $as = 'carl')
    {
        return $this->api($this->f[$as], $this->f['ws'], 'POST', "approvals/{$approval}/checkout", ['idempotency_key' => $key]);
    }

    private function hook(array $body, ?string $event = null, ?string $secret = null, ?string $ws = null)
    {
        $raw = json_encode($body);
        return $this->call('POST', '/api/v1/webhooks/razorpay/' . ($ws ?? $this->f['ws']), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $raw, $secret ?? self::HOOK),
            'HTTP_X_RAZORPAY_EVENT_ID' => $event ?? 'evt_' . bin2hex(random_bytes(6)),
        ], $raw);
    }

    private function captured(string $orderId, int $amount = 10000, string $currency = 'INR', string $pay = 'pay_Test0000000001'): array
    {
        return ['entity' => 'event', 'event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => $pay, 'order_id' => $orderId, 'amount' => $amount, 'currency' => $currency, 'status' => 'captured']]]];
    }

    private function order(?string $id = null): object
    {
        return $id ? $this->owner()->selectOne('SELECT * FROM payment_orders WHERE id = ?', [$id])
            : $this->owner()->selectOne('SELECT * FROM payment_orders ORDER BY created_at DESC LIMIT 1');
    }

    private function startWork()
    {
        $v = $this->owner()->selectOne('SELECT version FROM tickets WHERE id = ?', [$this->f['ticket']])->version;
        return $this->api($this->f['tina'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/transition", ['to' => 'ready_for_work', 'version' => $v]);
    }

    // --------------------------------------------------------- merchant setup
    public function test_merchant_setup_is_owner_only_with_mfa_test_mode_and_secrets_never_returned(): void
    {
        $this->api($this->f['olive'], $this->f['ws'], 'PUT', 'billing/merchant',
            ['legal_name' => 'X', 'razorpay_key_id' => self::KEY, 'key_secret' => self::SECRET, 'webhook_secret' => self::HOOK])->assertStatus(403);
        foreach (['tina', 'bea', 'carl'] as $u) {
            $this->api($this->f[$u], $this->f['ws'], 'PUT', 'billing/merchant',
                ['legal_name' => 'Mitra', 'razorpay_key_id' => self::KEY, 'key_secret' => self::SECRET, 'webhook_secret' => self::HOOK], mfaFresh: true)->assertForbidden();
        }
        $r = $this->merchant()->assertOk();
        $r->assertJsonPath('merchant.mode', 'test')->assertJsonPath('live_allowed', false);
        $this->assertStringNotContainsString(self::SECRET, $r->getContent());
        $this->assertStringNotContainsString(self::HOOK, $r->getContent());
        $row = $this->owner()->selectOne('SELECT * FROM merchant_profiles');
        $this->assertStringNotContainsString(self::SECRET, $row->key_secret_enc);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->owner()->select('SELECT * FROM audit_events')));
        // Billing can read the profile, technicians and clients cannot.
        $this->api($this->f['bea'], $this->f['ws'], 'GET', 'billing/merchant')->assertOk();
        $this->api($this->f['tina'], $this->f['ws'], 'GET', 'billing/merchant')->assertForbidden();
        // The API role can never read the encrypted secrets either.
        \App\Support\TenantContext::apply($this->f['olive']->id, $this->f['ws']);
        try {
            DB::connection('pgsql')->transaction(fn () => DB::select('SELECT key_secret_enc FROM merchant_profiles'));
            $this->fail('API role read merchant secrets');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
        } finally {
            \App\Support\TenantContext::clear();
        }
    }

    public function test_live_charging_is_impossible_until_the_founder_enables_it(): void
    {
        // 1. API refuses live keys.
        $this->merchant(['razorpay_key_id' => 'rzp_live_AbCdEf123456'])->assertStatus(422);
        // 2. Even with the platform flag on, the database control is off.
        config(['upfixy.payments.live' => true]);
        $this->merchant(['razorpay_key_id' => 'rzp_live_AbCdEf123456'])->assertStatus(422);
        // 3. Writing a live profile straight into the database is refused.
        $this->merchant()->assertOk();
        try {
            $this->owner()->update("UPDATE merchant_profiles SET mode = 'live', razorpay_key_id = 'rzp_live_AbCdEf123456'");
            $this->fail('live mode was stored');
        } catch (QueryException $e) {
            $this->assertStringContainsString('live payments are not enabled', $e->getMessage());
        }
        // 4. A test key can never be labelled live, or a live key test.
        try {
            $this->owner()->update("UPDATE merchant_profiles SET razorpay_key_id = 'rzp_live_AbCdEf123456'");
            $this->fail('live key in test mode was stored');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        // 4b. Fail closed: with the control row missing, live is still refused.
        $this->owner()->delete('DELETE FROM payment_controls');
        try {
            $this->owner()->update("UPDATE merchant_profiles SET mode = 'live', razorpay_key_id = 'rzp_live_AbCdEf123456'");
            $this->fail('live mode stored with no control row');
        } catch (QueryException $e) {
            $this->assertStringContainsString('live payments are not enabled', $e->getMessage());
        }
        // 5. The real HTTP client refuses live keys before any network call.
        try {
            (new \App\Payments\HttpRazorpayClient('https://unreachable.invalid'))->createOrder('rzp_live_AbCdEf123456', 's', 100, 'INR', 'r', []);
            $this->fail('live key used');
        } catch (\App\Payments\PaymentError $e) {
            $this->assertStringContainsString('Live payments are disabled', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ checkout
    public function test_checkout_is_payer_only_idempotent_and_creates_one_razorpay_order(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $this->checkout($approval, as: 'tina')->assertForbidden();
        $this->api($this->f['bob'], $this->f['wsB'], 'POST', "approvals/{$approval}/checkout", ['idempotency_key' => 'idem-key-000001'])->assertNotFound();

        $r = $this->checkout($approval)->assertOk();
        $r->assertJsonPath('checkout.key', self::KEY)->assertJsonPath('checkout.amount', 10000)->assertJsonPath('mode', 'test');
        $this->assertStringNotContainsString(self::SECRET, $r->getContent());
        // Double click, new tab, retry with another key: same order, Razorpay called once.
        $this->checkout($approval)->assertOk()->assertJsonPath('order_id', $r->json('order_id'));
        $this->checkout($approval, 'idem-key-000002')->assertOk()->assertJsonPath('order_id', $r->json('order_id'));
        $this->assertCount(1, array_filter($this->rzp->calls, fn ($c) => $c[0] === 'createOrder'));
        $this->assertSame('pending', $this->order()->status);
        // Price comes from the quote, not the request; secret used server-side only.
        $this->assertSame([10000, self::SECRET], [$this->rzp->calls[0][3], $this->rzp->calls[0][2]]);
    }

    public function test_razorpay_outage_at_checkout_leaves_a_retryable_order(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $this->rzp->down = true;
        $this->checkout($approval)->assertStatus(502);
        $this->assertNull($this->owner()->selectOne('SELECT id FROM payment_orders'));   // nothing half-made is kept
        $this->rzp->down = false;
        $this->checkout($approval)->assertOk();
        $this->assertSame('pending', $this->order()->status);
        $this->assertSame(1, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_orders')->n);
    }

    public function test_browser_success_is_not_proof_of_payment(): void // AC-SVC-004.2
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $c = $this->checkout($approval)->json('checkout');
        $order = $this->order()->id;
        $sig = hash_hmac('sha256', "{$c['order_id']}|pay_Browser0000001", self::SECRET);
        $this->api($this->f['carl'], $this->f['ws'], 'POST', "payments/{$order}/client-confirm",
            ['razorpay_payment_id' => 'pay_Browser0000001', 'razorpay_order_id' => $c['order_id'], 'razorpay_signature' => str_repeat('0', 64)])->assertStatus(422);
        $this->api($this->f['carl'], $this->f['ws'], 'POST', "payments/{$order}/client-confirm",
            ['razorpay_payment_id' => 'pay_Browser0000001', 'razorpay_order_id' => $c['order_id'], 'razorpay_signature' => $sig])
            ->assertOk()->assertJsonPath('status', 'awaiting_confirmation');
        $o = $this->order($order);
        $this->assertSame('pending', $o->status);
        $this->assertNotNull($o->client_reported_at);
        $this->assertFalse((bool) $this->owner()->selectOne('SELECT payment_verified FROM approvals WHERE id = ?', [$approval])->payment_verified);
        $this->startWork()->assertStatus(422);
    }

    // ------------------------------------------------------------ webhooks
    public function test_signed_webhook_verifies_payment_once_issues_a_receipt_and_unblocks_work(): void // AC-SVC-004.1, 005.1
    {
        $this->merchant()->assertOk();
        [$quote, $approval] = $this->approved();
        $c = $this->checkout($approval)->json('checkout');
        // The same event delivered three times, plus Razorpay's sibling order.paid event for the same payment.
        foreach ([1, 2, 3] as $i) $this->hook($this->captured($c['order_id']), 'evt_SameEvent0001')->assertOk();
        $this->hook(['event' => 'order.paid', 'payload' => $this->captured($c['order_id'])['payload']], 'evt_OrderPaid0001')
            ->assertOk()->assertJsonPath('outcome', 'already_paid');

        $o = $this->order();
        $this->assertSame('paid_verified', $o->status);
        $this->assertSame(1, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_orders')->n);
        $this->assertSame(1, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_receipts')->n);
        $this->assertSame(2, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_webhook_inbox')->n);
        $this->assertTrue((bool) $this->owner()->selectOne('SELECT payment_verified FROM approvals WHERE id = ?', [$approval])->payment_verified);

        // The receipt matches the quote and merchant, and says plainly it is not a tax invoice (D-14).
        $rc = $this->owner()->selectOne('SELECT * FROM payment_receipts');
        $q = $this->owner()->selectOne('SELECT description, amount_paise FROM quotes WHERE id = ?', [$quote]);
        $this->assertSame(['Mitra Web Services LLP', 'Mitra Traders', $q->description, (int) $q->amount_paise], [$rc->seller_name, $rc->buyer_name, $rc->description, (int) $rc->amount_paise]);
        $this->assertFalse((bool) $rc->is_tax_invoice);
        $this->assertStringContainsString('Not a GST tax invoice', $rc->tax_note);
        $this->assertMatchesRegularExpression('/^UPX-\d{4}-000001$/', $rc->number);
        // The payer sees it; work can start.
        $this->api($this->f['carl'], $this->f['ws'], 'GET', "tickets/{$this->f['ticket']}/payments")->assertOk()
            ->assertJsonPath('data.0.status', 'paid_verified')->assertJsonPath('data.0.receipt.number', $rc->number);
        $this->startWork()->assertOk();
    }

    public function test_unsigned_wrongly_signed_or_misrouted_webhooks_change_nothing(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $c = $this->checkout($approval)->json('checkout');
        $this->hook($this->captured($c['order_id']), secret: 'not-the-secret-000')->assertStatus(401);
        $this->hook($this->captured($c['order_id']), ws: $this->f['wsB'])->assertStatus(401);         // workspace without a profile
        $this->hook($this->captured($c['order_id']), ws: '00000000-0000-4000-8000-000000000000')->assertStatus(401);
        $this->call('POST', "/api/v1/webhooks/razorpay/{$this->f['ws']}", [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($this->captured($c['order_id'])))->assertStatus(401);
        $this->assertSame('pending', $this->order()->status);
        $this->assertSame(0, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_webhook_inbox')->n);
    }

    public function test_wrong_amount_currency_or_merchant_is_not_fulfilled_and_raises_an_alert(): void // AC-SVC-004.3
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $c = $this->checkout($approval)->json('checkout');
        $this->hook($this->captured($c['order_id'], 100))->assertOk()->assertJsonPath('outcome', 'mismatch');
        $this->hook($this->captured($c['order_id'], 10000, 'USD'))->assertOk()->assertJsonPath('outcome', 'mismatch');
        // The merchant's keys changed since the order was created: not the merchant that took the order.
        $this->merchant(['razorpay_key_id' => 'rzp_test_ZzZzZz999999'])->assertOk();
        $this->hook($this->captured($c['order_id']))->assertOk()->assertJsonPath('outcome', 'mismatch');
        // An order id that belongs to another workspace's merchant.
        $this->hook($this->captured('order_Elsewhere000001'))->assertOk()->assertJsonPath('outcome', 'unknown_order');

        $this->assertSame('pending', $this->order()->status);
        $this->assertFalse((bool) $this->owner()->selectOne('SELECT payment_verified FROM approvals WHERE id = ?', [$approval])->payment_verified);
        $this->assertSame(0, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_receipts')->n);
        $alerts = $this->api($this->f['bea'], $this->f['ws'], 'GET', 'billing/payments')->assertOk()->json('alerts');
        $this->assertSame(['mismatch', 'mismatch', 'mismatch', 'unknown_order'], array_reverse(array_column($alerts, 'kind')));
        $this->startWork()->assertStatus(422);
    }

    public function test_failed_payment_allows_retry_and_a_late_failure_never_undoes_a_payment(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $c1 = $this->checkout($approval)->json('checkout');
        $fail = fn ($oid) => ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_Failed00000001', 'order_id' => $oid, 'amount' => 10000, 'currency' => 'INR', 'status' => 'failed', 'error_description' => 'Card declined']]]];
        $this->hook($fail($c1['order_id']))->assertJsonPath('outcome', 'failed');
        $this->assertSame('failed', $this->order()->status);
        // A new attempt gets a new order.
        $c2 = $this->checkout($approval, 'idem-key-000002')->json('checkout');
        $this->assertNotSame($c1['order_id'], $c2['order_id']);
        $this->hook($this->captured($c2['order_id']))->assertJsonPath('outcome', 'paid');
        // Out-of-order failure for the paid order is ignored.
        $this->hook($fail($c2['order_id']))->assertJsonPath('outcome', 'ignored');
        $this->assertSame('paid_verified', $this->order()->status);
        // Once paid, checkout refuses.
        $this->checkout($approval, 'idem-key-000003')->assertStatus(422);
    }

    public function test_refunds_are_requested_by_billing_and_only_processed_by_verified_events(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $c = $this->checkout($approval)->json('checkout');
        $this->hook($this->captured($c['order_id']))->assertOk();
        $order = $this->order()->id;

        $this->api($this->f['tina'], $this->f['ws'], 'POST', "payments/{$order}/refunds", ['amount_paise' => 4000, 'reason' => 'Partial work'], mfaFresh: true)->assertForbidden();
        $this->api($this->f['bea'], $this->f['ws'], 'POST', "payments/{$order}/refunds", ['amount_paise' => 20000, 'reason' => 'Too much'], mfaFresh: true)->assertStatus(422);
        $this->api($this->f['bea'], $this->f['ws'], 'POST', "payments/{$order}/refunds", ['amount_paise' => 4000, 'reason' => 'Partial work'], mfaFresh: true)->assertStatus(202);
        $rfnd = array_key_first($this->rzp->refunds);
        $this->assertSame('paid_verified', $this->order($order)->status); // requested ≠ refunded
        $refundEvent = fn ($id, $amt, $type = 'refund.processed') => ['event' => $type, 'payload' => ['refund' => ['entity' => [
            'id' => $id, 'payment_id' => 'pay_Test0000000001', 'amount' => $amt, 'status' => $type === 'refund.failed' ? 'failed' : 'processed']]]];
        $this->hook($refundEvent($rfnd, 4000), 'evt_Refund000001')->assertJsonPath('outcome', 'refunded');
        $this->hook($refundEvent($rfnd, 4000), 'evt_Refund000001')->assertJsonPath('outcome', 'duplicate');
        $o = $this->order($order);
        $this->assertSame(['partially_refunded', 4000], [$o->status, (int) $o->refunded_paise]);
        // The rest refunded from the Razorpay dashboard is recorded too.
        $this->hook($refundEvent('rfnd_Dashboard000001', 6000), 'evt_Refund000002')->assertJsonPath('outcome', 'refunded');
        $o = $this->order($order);
        $this->assertSame(['refunded', 10000], [$o->status, (int) $o->refunded_paise]);
        $this->assertSame(2, (int) $this->owner()->selectOne("SELECT count(*) AS n FROM refunds WHERE status = 'processed'")->n);
    }

    // --------------------------------------------------- catalogue, coverage
    public function test_prices_are_server_set_and_scope_cannot_be_raised_on_an_existing_quote(): void // SVC-002, AC-SVC-002.1
    {
        $f = $this->f;
        $quote = fn ($code, $extra = []) => $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/quotes",
            ['monitor_target_id' => $f['target'], 'action_code' => $code, 'description' => 'Scoped work', ...$extra]);
        $quote('dns.record_fix', ['amount_paise' => 1])->assertCreated()->assertJsonPath('amount_paise', 10000);
        $quote('cpanel.ssl_fix', ['amount_paise' => 99])->assertCreated()->assertJsonPath('amount_paise', 20000);
        $quote('code.small_fix', ['amount_paise' => 999999])->assertCreated()->assertJsonPath('amount_paise', 30000);
        $quote('custom.quoted_work')->assertStatus(422);
        $id = $quote('custom.quoted_work', ['amount_paise' => 250000])->assertCreated()->assertJsonPath('amount_paise', 250000)->json('id');
        // A technician cannot raise the price of an issued quote; a bigger job needs a new quote (and new approval).
        try {
            $this->owner()->update('UPDATE quotes SET amount_paise = 90000 WHERE id = ?', [$id]);
            $this->fail('quote price was edited');
        } catch (QueryException $e) {
            $this->assertStringContainsString('quotes cannot be edited', $e->getMessage());
        }
    }

    public function test_free_work_needs_active_coverage_and_customer_approval(): void // SVC-002 ₹0, AC-SVC-003.3
    {
        $f = $this->f;
        $q = fn () => $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/quotes",
            ['monitor_target_id' => $f['target'], 'action_code' => 'provider.covered_fix', 'description' => 'Restart PHP-FPM pool under AMC']);
        $q()->assertStatus(422)->assertJsonPath('code', 'coverage_required');
        // Expired or other-client coverage doesn't count.
        $other = $this->makeClient($f['ws'], 'Other');
        $this->cover($f['ws'], $other);
        $this->owner()->insert("INSERT INTO coverage_contracts(workspace_id, client_id, reference, scope, valid_from, valid_until, created_by)
                                VALUES (?, ?, 'AMC-OLD', 'Expired plan', current_date - 400, current_date - 35, ?)", [$f['ws'], $f['client'], $f['olive']->id]);
        $q()->assertStatus(422);
        // Coverage contracts are owner/admin + MFA.
        $this->api($f['tina'], $f['ws'], 'POST', 'billing/coverage', ['client_id' => $f['client'], 'reference' => 'AMC-1', 'scope' => 'Hosting AMC',
            'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addYear()->toDateString()], mfaFresh: true)->assertForbidden();
        $cov = $this->api($f['olive'], $f['ws'], 'POST', 'billing/coverage', ['client_id' => $f['client'], 'reference' => 'AMC-2026-14', 'scope' => 'Hosting AMC',
            'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addYear()->toDateString()], mfaFresh: true)->assertCreated()->json('id');
        $quote = $q()->assertCreated()->assertJsonPath('amount_paise', 0);
        $this->assertSame($cov, json_decode($this->owner()->selectOne('SELECT params FROM quotes WHERE id = ?', [$quote->json('id')])->params, true)['coverage_id']);
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/transition", ['to' => 'quote_pending', 'version' => 2])->assertOk();
        // ₹0 still needs the customer's approval, and no checkout.
        $this->startWork()->assertStatus(422);
        $a = $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$f['ticket']}/approvals",
            ['quote_id' => $quote->json('id'), 'fingerprint' => $quote->json('fingerprint')])->assertCreated()->json('id');
        $this->merchant()->assertOk();
        $this->checkout($a)->assertStatus(422);
        // Coverage revoked before work starts: blocked.
        $this->api($f['olive'], $f['ws'], 'POST', "billing/coverage/{$cov}/revoke", [], mfaFresh: true)->assertOk();
        $this->startWork()->assertStatus(422);
        // Restored coverage (a new contract cannot be swapped in: the quote names the old one; a new quote is needed).
        $this->cover($f['ws'], $f['client']);
        $this->startWork()->assertStatus(422);
    }

    // -------------------------------------------------------- reconciliation
    public function test_reconciliation_asks_razorpay_and_applies_the_same_checks(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $c = $this->checkout($approval)->json('checkout');
        $this->owner()->update("UPDATE payment_orders SET created_at = now() - interval '1 hour'");
        $this->rzp->down = true;
        $this->assertSame(['unreachable'], array_values(app(Reconciler::class)->runOnce(600)));
        $this->rzp->down = false;
        $this->rzp->payments[$c['order_id']] = [['id' => 'pay_Short000000001', 'status' => 'captured', 'amount' => 500, 'currency' => 'INR', 'order_id' => $c['order_id']]];
        $this->assertSame(['mismatch'], array_values(app(Reconciler::class)->runOnce(600)));
        $this->assertSame('pending', $this->order()->status);
        $this->rzp->payments[$c['order_id']] = [['id' => 'pay_Good0000000001', 'status' => 'captured', 'amount' => 10000, 'currency' => 'INR', 'order_id' => $c['order_id']]];
        $this->assertSame(['paid'], array_values(app(Reconciler::class)->runOnce(600)));
        $this->assertSame('paid_verified', $this->order()->status);
        // A later webhook for the same payment changes nothing.
        $this->hook($this->captured($c['order_id'], pay: 'pay_Good0000000001'))->assertJsonPath('outcome', 'already_paid');
        $this->assertSame(1, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM payment_receipts')->n);
    }

    public function test_the_api_role_cannot_mark_anything_paid(): void
    {
        $this->merchant()->assertOk();
        [, $approval] = $this->approved();
        $this->checkout($approval)->assertOk();
        \App\Support\TenantContext::apply($this->f['olive']->id, $this->f['ws']);
        foreach (["UPDATE approvals SET payment_verified = true", "UPDATE payment_orders SET status = 'paid_verified'",
                     "SELECT payment_captured(gen_random_uuid(), 'order_x', 'pay_x', 1, 'INR', 'k')",
                     "INSERT INTO payment_receipts(workspace_id, order_id, number, seller_name, description, action_code, amount_paise, currency, tax_note, mode, provider_payment_id) VALUES (gen_random_uuid(), gen_random_uuid(), 'x', 'x', 'x', 'x', 1, 'INR', 'x', 'test', 'x')"] as $sql) {
            try {
                DB::connection('pgsql')->transaction(fn () => DB::statement($sql));
                $this->fail("API role ran: {$sql}");
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['42501', '42883'], $sql);
            }
        }
        \App\Support\TenantContext::clear();
        $this->assertSame('pending', $this->order()->status);
    }
}
