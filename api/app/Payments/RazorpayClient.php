<?php

namespace App\Payments;

/** The Razorpay calls Upfixy makes (EXT: Razorpay Orders/Payments/Refunds API v1; verify in the sandbox). */
interface RazorpayClient
{
    /** @return array{id: string, status: string} */
    public function createOrder(string $keyId, string $keySecret, int $amountPaise, string $currency, string $receipt, array $notes): array;

    /** @return list<array{id: string, status: string, amount: int, currency: string, order_id: string}> */
    public function orderPayments(string $keyId, string $keySecret, string $orderId): array;

    /** @return array{id: string, status: string} */
    public function refund(string $keyId, string $keySecret, string $paymentId, int $amountPaise, array $notes): array;
}
