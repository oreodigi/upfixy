<?php

namespace App\Payments;

/** Razorpay signature checks (EXT: Razorpay docs, "Verify payment signature" and "Validate webhooks"). */
final class Signatures
{
    /** Checkout handler: HMAC-SHA256("{order_id}|{payment_id}", key_secret). */
    public static function checkout(string $orderId, string $paymentId, string $signature, string $keySecret): bool
    {
        return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret), strtolower($signature));
    }

    /** Webhook: HMAC-SHA256(raw body, webhook secret), hex, in X-Razorpay-Signature. */
    public static function webhook(string $rawBody, string $signature, string $secret): bool
    {
        return $secret !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }
}
