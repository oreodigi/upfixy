<?php

namespace App\Repairs;

use RuntimeException;

/**
 * Credentials for DNS edits are sealed to the executor's public key
 * (libsodium sealed box, NFR-13 "separated keys"). The API process holds
 * only the PUBLIC key: it can store a token but never read it back. Only
 * the executor process is configured with the key pair.
 */
final class SecretBox
{
    public static function keygen(): array
    {
        $kp = sodium_crypto_box_keypair();
        return ['public' => base64_encode(sodium_crypto_box_publickey($kp)), 'keypair' => base64_encode($kp)];
    }

    public static function seal(string $plain, ?string $publicKeyB64 = null): string
    {
        $pk = base64_decode((string) ($publicKeyB64 ?? config('upfixy.repairs.public_key')), true);
        if (!$pk || strlen($pk) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) throw new RuntimeException('repair sealing key is not configured');
        return 'v1:' . base64_encode(sodium_crypto_box_seal($plain, $pk));
    }

    public static function open(string $sealed, ?string $keypairB64 = null): string
    {
        $kp = base64_decode((string) ($keypairB64 ?? config('upfixy.repairs.keypair')), true);
        if (!$kp || strlen($kp) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) throw new RuntimeException('executor key pair is not configured');
        if (!str_starts_with($sealed, 'v1:')) throw new RuntimeException('unsupported sealed format');
        $plain = sodium_crypto_box_seal_open(base64_decode(substr($sealed, 3), true) ?: '', $kp);
        if ($plain === false) throw new RuntimeException('credential cannot be opened with this key');
        return $plain;
    }
}
