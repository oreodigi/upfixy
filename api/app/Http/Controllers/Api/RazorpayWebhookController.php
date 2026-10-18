<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Razorpay webhooks (SVC-004, AC-SVC-004.1/.3). No session, no tenant context: the signature over the
 * raw body with the workspace's webhook secret is the only authentication. Every delivery is stored once
 * by event id; duplicates are acknowledged and ignored. Writes go through upfixy_worker functions.
 */
class RazorpayWebhookController
{
    public function __invoke(Request $request, string $workspace): JsonResponse
    {
        if (!Str::isUuid($workspace)) return response()->json(['ok' => false], 404);
        $w = DB::connection('pgsql_worker');
        $raw = $request->getContent();
        $sig = (string) $request->header('X-Razorpay-Signature', '');
        $event = (string) $request->header('X-Razorpay-Event-Id', '');
        $enc = $w->selectOne('SELECT payment_webhook_secret(?) AS s', [$workspace])?->s;
        try {
            $secret = $enc ? Crypt::decryptString($enc) : '';
        } catch (Throwable) {
            $secret = '';
        }
        // Same answer for an unknown workspace and a bad signature.
        if (!\App\Payments\Signatures::webhook($raw, $sig, $secret) || !preg_match('/^[A-Za-z0-9_-]{6,100}$/', $event)) {
            return response()->json(['ok' => false], 401);
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) return response()->json(['ok' => false], 400);
        $type = (string) ($j['event'] ?? '');
        $payment = $j['payload']['payment']['entity'] ?? [];
        $refund = $j['payload']['refund']['entity'] ?? [];

        return $w->transaction(function () use ($w, $workspace, $event, $type, $raw, $payment, $refund) {
            // Record the delivery FIRST. A duplicate (same event id) finds the row and does nothing;
            // two concurrent deliveries serialise on the primary key (AC-SVC-004.1).
            $first = $w->selectOne('SELECT payment_inbox(?, ?, ?, ?, ?, NULL) AS ok', [$event, $workspace, substr($type, 0, 60), hash('sha256', $raw), 'received'])->ok;
            if (!$first) return response()->json(['ok' => true, 'outcome' => 'duplicate']);
            $outcome = match ($type) {
                'payment.captured', 'order.paid' => ($payment['status'] ?? '') === 'captured'
                    ? $w->selectOne('SELECT payment_captured(?, ?, ?, ?, ?, (SELECT razorpay_key_id FROM payment_merchant_key(?))) AS r',
                        [$workspace, (string) ($payment['order_id'] ?? ''), (string) ($payment['id'] ?? ''), (int) ($payment['amount'] ?? -1),
                            (string) ($payment['currency'] ?? ''), $workspace])->r
                    : 'not_captured',
                'payment.failed' => $w->selectOne('SELECT payment_failed(?, ?, ?) AS r', [$workspace, (string) ($payment['order_id'] ?? ''),
                    substr((string) ($payment['error_description'] ?? 'Payment failed'), 0, 200)])->r,
                'refund.processed', 'refund.failed' => $w->selectOne('SELECT payment_refunded(?, ?, ?, ?, ?) AS r', [$workspace,
                    (string) ($refund['payment_id'] ?? ''), (string) ($refund['id'] ?? ''), (int) ($refund['amount'] ?? 0),
                    $type === 'refund.failed' ? 'failed' : 'processed'])->r,
                default => 'ignored',
            };
            return response()->json(['ok' => true, 'outcome' => $outcome]);
        });
    }
}
