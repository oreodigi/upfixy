<?php

namespace App\Payments;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Server-side reconciliation (§19.2): for orders still pending after a while, ask Razorpay directly.
 * Same checks as a webhook; runs as upfixy_worker. Never trusts the browser.
 */
final class Reconciler
{
    public function __construct(private RazorpayClient $rzp) {}

    public function runOnce(int $olderThanSeconds = 600, int $limit = 50): array
    {
        $w = DB::connection('pgsql_worker');
        $out = [];
        foreach ($w->select('SELECT * FROM payment_pending_orders(?, ?)', [$olderThanSeconds, $limit]) as $o) {
            $m = $w->selectOne('SELECT * FROM payment_merchant_key(?)', [$o->workspace_id]);
            if (!$m) continue;
            try {
                $payments = $this->rzp->orderPayments($m->razorpay_key_id, Crypt::decryptString($m->key_secret_enc), $o->provider_order_id);
            } catch (Throwable) {
                $out[$o->id] = 'unreachable';
                continue;
            }
            $result = 'pending';
            foreach ($payments as $p) {
                if ($p['order_id'] !== $o->provider_order_id) continue;
                if ($p['status'] === 'captured') {
                    $result = $w->selectOne('SELECT payment_captured(?, ?, ?, ?, ?, ?) AS r',
                        [$o->workspace_id, $o->provider_order_id, $p['id'], $p['amount'], $p['currency'], $m->razorpay_key_id])->r;
                    break;
                }
            }
            if ($result === 'pending' && $payments !== [] && collect($payments)->every(fn ($p) => $p['status'] === 'failed')) {
                $result = $w->selectOne('SELECT payment_failed(?, ?, ?) AS r', [$o->workspace_id, $o->provider_order_id, 'Payment failed at Razorpay'])->r;
            }
            $out[$o->id] = $result;
        }
        return $out;
    }
}
