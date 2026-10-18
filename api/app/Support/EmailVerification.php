<?php

namespace App\Support;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** One-time, expiring email links. Only the SHA-256 of the token is stored. */
final class EmailVerification
{
    public static function send(User $user): void
    {
        $token = bin2hex(random_bytes(32));
        DB::select('SELECT issue_email_verification(?, ?)', [hash('sha256', $token), config('upfixy.email_verification_minutes')]);
        $link = config('upfixy.frontend_url') . '/verify-email?token=' . $token;
        Mail::to($user->email)->send(new VerifyEmailMail($link));
    }

    /** @return ?string the verified user's id, or null if the link is invalid, used or expired */
    public static function confirm(string $token): ?string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return DB::selectOne('SELECT confirm_email_verification(?) AS u', [hash('sha256', $token)])->u;
    }
}
