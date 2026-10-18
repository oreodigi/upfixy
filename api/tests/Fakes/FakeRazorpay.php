<?php

namespace Tests\Fakes;

use App\Payments\PaymentError;
use App\Payments\RazorpayClient;

final class FakeRazorpay implements RazorpayClient
{
    public array $orders = [];
    /** @var array<string, list<array>> order id => payments */
    public array $payments = [];
    public array $refunds = [];
    public array $calls = [];
    public bool $down = false;

    public function createOrder(string $keyId, string $keySecret, int $amountPaise, string $currency, string $receipt, array $notes): array
    {
        $this->calls[] = ['createOrder', $keyId, $keySecret, $amountPaise];
        if ($this->down) throw new PaymentError('Razorpay could not be reached. Please try again.');
        if (str_starts_with($keyId, 'rzp_live_')) throw new PaymentError('Live payments are disabled on this platform.');
        $id = 'order_' . substr(md5($receipt . count($this->orders)), 0, 14);
        $this->orders[$id] = ['amount' => $amountPaise, 'currency' => $currency, 'receipt' => $receipt];
        return ['id' => $id, 'status' => 'created'];
    }

    public function orderPayments(string $keyId, string $keySecret, string $orderId): array
    {
        $this->calls[] = ['orderPayments', $orderId];
        if ($this->down) throw new PaymentError('down');
        return $this->payments[$orderId] ?? [];
    }

    public function refund(string $keyId, string $keySecret, string $paymentId, int $amountPaise, array $notes): array
    {
        $this->calls[] = ['refund', $paymentId, $amountPaise];
        $id = 'rfnd_' . substr(md5($paymentId . count($this->refunds)), 0, 14);
        $this->refunds[$id] = ['payment' => $paymentId, 'amount' => $amountPaise];
        return ['id' => $id, 'status' => 'pending'];
    }
}
