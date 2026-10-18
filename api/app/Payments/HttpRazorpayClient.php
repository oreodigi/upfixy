<?php

namespace App\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay REST v1 with basic auth (key id + key secret). Refuses live keys unless BOTH the platform
 * flag and the database payment control allow live charging — today neither does.
 */
final class HttpRazorpayClient implements RazorpayClient
{
    public function __construct(private string $base = 'https://api.razorpay.com/v1', private int $timeout = 20) {}

    public static function liveAllowed(): bool
    {
        return (bool) config('upfixy.payments.live') && (bool) DB::selectOne('SELECT live_allowed FROM payment_controls')?->live_allowed;
    }

    private function guard(string $keyId): void
    {
        if (str_starts_with($keyId, 'rzp_live_') && !self::liveAllowed()) throw new PaymentError('Live payments are disabled on this platform.');
        if (!preg_match('/^rzp_(test|live)_[A-Za-z0-9]{8,32}$/', $keyId)) throw new PaymentError('Razorpay key id is not valid.');
    }

    public function createOrder(string $keyId, string $keySecret, int $amountPaise, string $currency, string $receipt, array $notes): array
    {
        $this->guard($keyId);
        $j = $this->send('post', '/orders', $keyId, $keySecret, ['amount' => $amountPaise, 'currency' => $currency, 'receipt' => $receipt, 'notes' => $notes]);
        return ['id' => (string) ($j['id'] ?? ''), 'status' => (string) ($j['status'] ?? '')];
    }

    public function orderPayments(string $keyId, string $keySecret, string $orderId): array
    {
        $this->guard($keyId);
        if (!preg_match('/^order_[A-Za-z0-9]{6,40}$/', $orderId)) throw new PaymentError('Order id is not valid.');
        $j = $this->send('get', "/orders/{$orderId}/payments", $keyId, $keySecret, null);
        return array_map(fn ($p) => ['id' => (string) $p['id'], 'status' => (string) $p['status'], 'amount' => (int) $p['amount'],
            'currency' => (string) $p['currency'], 'order_id' => (string) ($p['order_id'] ?? '')], (array) ($j['items'] ?? []));
    }

    public function refund(string $keyId, string $keySecret, string $paymentId, int $amountPaise, array $notes): array
    {
        $this->guard($keyId);
        if (!preg_match('/^pay_[A-Za-z0-9]{6,40}$/', $paymentId)) throw new PaymentError('Payment id is not valid.');
        $j = $this->send('post', "/payments/{$paymentId}/refund", $keyId, $keySecret, ['amount' => $amountPaise, 'notes' => $notes]);
        return ['id' => (string) ($j['id'] ?? ''), 'status' => (string) ($j['status'] ?? '')];
    }

    private function send(string $method, string $path, string $keyId, string $keySecret, ?array $body): array
    {
        try {
            $req = Http::withBasicAuth($keyId, $keySecret)->acceptJson()->timeout($this->timeout);
            $r = $method === 'get' ? $req->get($this->base . $path) : $req->post($this->base . $path, $body);
        } catch (ConnectionException) {
            throw new PaymentError('Razorpay could not be reached. Please try again.');
        }
        if (!$r->successful()) {
            throw new PaymentError($r->status() === 401 ? 'Razorpay did not accept the merchant keys.' : 'Razorpay refused the request.');
        }
        return (array) $r->json();
    }
}
