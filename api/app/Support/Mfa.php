<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) for the CURRENT user. Secrets are encrypted with APP_KEY and
 * only readable through auth_my_mfa(). A code's time step can be used once.
 */
final class Mfa
{
    private const WINDOW = 1; // ±30 s clock drift

    public function __construct(private Google2FA $g = new Google2FA()) {}

    public function state(): ?object
    {
        return DB::selectOne('SELECT * FROM auth_my_mfa()');
    }

    /** @return array{secret: string, otpauth_url: string} */
    public function begin(string $email): array
    {
        $secret = $this->g->generateSecretKey(32);
        DB::update('UPDATE users SET mfa_pending_secret_enc = ? WHERE id = ?', [Crypt::encryptString($secret), TenantContext::userId()]);
        return ['secret' => $secret, 'otpauth_url' => $this->g->getQRCodeUrl('Upfixy', $email, $secret)];
    }

    /** @return ?string[] recovery codes (shown once) on success */
    public function confirm(string $code): ?array
    {
        $s = $this->state();
        if (!$s?->pending_secret_enc) return null;
        $secret = Crypt::decryptString($s->pending_secret_enc);
        // Old step 0 (not null) makes the library return the matched time step, not just true.
        $step = $this->g->verifyKeyNewer($secret, $this->normalise($code), 0, self::WINDOW);
        if ($step === false) return null;

        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtolower(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        $ok = DB::selectOne('SELECT auth_mfa_activate(?::text[], ?) AS ok',
            [$this->pgArray(array_map([$this, 'hashRecovery'], $codes)), $step])->ok;
        return $ok ? $codes : null;
    }

    /** Checks a TOTP code (or a one-time recovery code) against the enabled secret. */
    public function verify(string $code): bool
    {
        $s = $this->state();
        if (!$s?->secret_enc) return false;

        $norm = $this->normalise($code);
        if (preg_match('/^\d{6}$/', $norm)) {
            $step = $this->g->verifyKeyNewer(Crypt::decryptString($s->secret_enc), $norm, (int) ($s->last_step ?? 0), self::WINDOW);
            if ($step === false) return false;
            // Atomic in the database: two concurrent requests can't both use the same step.
            return (bool) DB::selectOne('SELECT auth_mfa_use_step(?) AS ok', [$step])->ok;
        }

        return (bool) DB::selectOne('SELECT auth_mfa_use_recovery(?) AS ok', [$this->hashRecovery($code)])->ok;
    }

    public function disable(): void
    {
        DB::update('UPDATE users SET mfa_secret_enc = NULL, mfa_pending_secret_enc = NULL, mfa_enabled_at = NULL,
                    mfa_recovery_hashes = NULL, mfa_last_step = NULL WHERE id = ?', [TenantContext::userId()]);
    }

    public static function markTokenFresh(int $tokenId): void
    {
        DB::update('UPDATE personal_access_tokens SET mfa_verified_at = now() WHERE id = ?', [$tokenId]);
    }

    private function normalise(string $code): string
    {
        $c = strtolower(preg_replace('/\s+/', '', $code));
        return preg_match('/^\d{3}-?\d{3}$/', $c) ? str_replace('-', '', $c) : $c;
    }

    private function hashRecovery(string $code): string
    {
        return hash_hmac('sha256', strtolower(preg_replace('/[\s-]+/', '', $code)), config('app.key'));
    }

    private function pgArray(array $values): string
    {
        return '{' . implode(',', $values) . '}'; // hex strings only, no quoting needed
    }

}
