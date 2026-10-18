<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Tokens
{
    public const SESSION_DAYS = 7;
    public const MFA_CHALLENGE_MINUTES = 5;

    /**
     * Issue an API token. Requires the user's tenant context to be applied
     * (RLS only lets a user insert their own tokens).
     */
    public static function issue(User $user, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null, bool $mfaVerified = false): string
    {
        $new = $user->createToken('web', $abilities, $expiresAt ?? now()->addDays(self::SESSION_DAYS));
        if ($mfaVerified) {
            DB::update('UPDATE personal_access_tokens SET mfa_verified_at = now() WHERE id = ?', [$new->accessToken->getKey()]);
        }
        return $new->plainTextToken;
    }
}
