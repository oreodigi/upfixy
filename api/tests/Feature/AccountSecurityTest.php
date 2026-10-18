<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Mail\VerifyEmailMail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** ACC-001/002/005: email verification, MFA, invitations, membership changes. */
class AccountSecurityTest extends TestCase
{
    private function linkToken(string $link): string
    {
        parse_str(parse_url($link, PHP_URL_QUERY), $q);
        return $q['token'];
    }

    // ---------------------------------------------------------------- email
    public function test_signup_sends_a_one_time_verification_link(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register', ['email' => 'new@test.local', 'password' => 'a long enough password',
            'account_type' => 'solo', 'workspace_name' => 'Mine'])->assertCreated()->assertJsonPath('user.email_verified', false);

        $link = null;
        Mail::assertSent(VerifyEmailMail::class, function ($m) use (&$link) { $link = $m->link; return $m->hasTo('new@test.local'); });
        $this->assertStringStartsWith(config('upfixy.frontend_url') . '/verify-email?token=', $link);

        $this->postJson('/api/v1/auth/verify-email', ['token' => $this->linkToken($link)])->assertOk();
        $this->postJson('/api/v1/auth/verify-email', ['token' => $this->linkToken($link)])->assertStatus(422);
        $this->assertNotNull($this->owner()->table('users')->where('email', 'new@test.local')->value('email_verified_at'));
        // Stored only as a hash:
        $this->assertSame(0, $this->owner()->table('email_verification_tokens')->where('token_hash', $this->linkToken($link))->count());
    }

    public function test_resending_invalidates_the_previous_link(): void
    {
        Mail::fake();
        $u = $this->makeUser('late', verified: false);
        $links = [];
        $this->api($u, null, 'POST', 'auth/verify-email/resend')->assertStatus(202);
        $this->api($u, null, 'POST', 'auth/verify-email/resend')->assertStatus(202);
        Mail::assertSent(VerifyEmailMail::class, function ($m) use (&$links) { $links[] = $m->link; return true; });
        $this->postJson('/api/v1/auth/verify-email', ['token' => $this->linkToken($links[0])])->assertStatus(422);
        $this->postJson('/api/v1/auth/verify-email', ['token' => $this->linkToken($links[1])])->assertOk();
    }

    // ------------------------------------------------------------------ MFA
    public function test_mfa_enrolment_then_login_needs_the_second_step(): void
    {
        $u = $this->makeUser('mia');
        $setup = $this->api($u, null, 'POST', 'mfa/setup')->assertOk();
        $this->assertStringStartsWith('otpauth://totp/Upfixy:mia%40test.local', $setup->json('otpauth_url'));
        $secret = $setup->json('secret');

        $this->api($u, null, 'POST', 'mfa/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->api($u, null, 'POST', 'mfa/confirm', ['code' => $this->totp($secret)])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        // Secret is encrypted at rest:
        $stored = $this->owner()->table('users')->where('id', $u->id)->value('mfa_secret_enc');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString($secret, $stored);

        // Password step returns only a challenge token...
        Auth::forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'mia@test.local', 'password' => 'correct horse battery'])
            ->assertOk()->assertJsonPath('mfa_required', true)->assertJsonMissing(['token']);
        $challenge = $login->json('challenge_token');
        // ...which can't do anything else:
        Auth::forgetGuards();
        $this->withToken($challenge)->getJson('/api/v1/workspaces')->assertForbidden()->assertJsonPath('code', 'mfa_challenge_pending');

        // A code already used in this time step is refused (replay); a recovery code works once.
        Auth::forgetGuards();
        $this->withToken($challenge)->postJson('/api/v1/auth/mfa/login', ['code' => $this->totp($secret)])->assertStatus(422);
        Auth::forgetGuards();
        $full = $this->withToken($challenge)->postJson('/api/v1/auth/mfa/login', ['code' => $codes[0]])->assertOk()->json('token');
        Auth::forgetGuards();
        $this->withToken($full)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('mfa_enabled', true)->assertJsonPath('mfa_fresh', true);
        // Challenge token is gone after use:
        Auth::forgetGuards();
        $this->withToken($challenge)->postJson('/api/v1/auth/mfa/login', ['code' => $codes[1]])->assertUnauthorized();
        // Used recovery code can't be reused:
        $this->api($u, null, 'POST', 'mfa/verify', ['code' => $codes[0]])->assertStatus(422);
        $this->api($u, null, 'POST', 'mfa/verify', ['code' => $codes[1]])->assertOk();
    }

    public function test_mfa_code_guessing_is_rate_limited_per_endpoint(): void
    {
        $u = $this->makeUser('guess');
        $this->enableMfa($u);
        for ($i = 0; $i < 5; $i++) {
            $this->api($u, null, 'POST', 'mfa/verify', ['code' => '000000'])->assertStatus(422);
        }
        // The 5th wrong code locks the account's code entry (SecurityHardeningTest); the 6/min limiter is the outer bound.
        $this->api($u, null, 'POST', 'mfa/verify', ['code' => '000000'])->assertStatus(429);
        $this->api($u, null, 'POST', 'mfa/verify', ['code' => '000000'])->assertStatus(429);
        // A different endpoint has its own budget, so one can't lock the user out of everything.
        $this->api($u, null, 'GET', 'auth/me')->assertOk();
    }

    public function test_step_up_marks_only_the_current_session_fresh(): void
    {
        $u = $this->makeUser('sam');
        $secret = $this->enableMfa($u);
        $this->api($u, null, 'GET', 'auth/me')->assertJsonPath('mfa_fresh', false);
        $this->api($u, null, 'POST', 'mfa/verify', ['code' => $this->totp($secret)])->assertOk();
        $this->api($u, null, 'GET', 'auth/me')->assertJsonPath('mfa_fresh', true);
    }

    public function test_expired_tokens_are_rejected(): void
    {
        $u = $this->makeUser('old');
        $token = $this->tokenFor($u);
        $this->owner()->update("UPDATE personal_access_tokens SET expires_at = now() - interval '1 second'");
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_all_revokes_every_session(): void
    {
        $u = $this->makeUser('lou');
        $t1 = $this->tokenFor($u);
        $t2 = $this->tokenFor($u, mfaFresh: true);
        Auth::forgetGuards();
        $this->withToken($t1)->postJson('/api/v1/auth/logout-all')->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($t2)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ------------------------------------------------------------ invitations
    private function agency(): array
    {
        $f = ['olive' => $this->makeUser('olive'), 'adam' => $this->makeUser('adam'), 'tina' => $this->makeUser('tina'), 'carl' => $this->makeUser('carl')];
        $f['ws'] = $this->makeWorkspace('Agency A');
        $this->addMember($f['ws'], $f['olive'], 'owner');
        $this->addMember($f['ws'], $f['adam'], 'admin');
        $this->addMember($f['ws'], $f['tina'], 'technician');
        $this->addMember($f['ws'], $f['carl'], 'client_owner');
        $f['client'] = $this->makeClient($f['ws'], 'Mitra');
        $this->assign($f['ws'], $f['client'], $f['carl']);
        $f['secret'] = $this->enableMfa($f['olive']);
        return $f;
    }

    public function test_invitations_need_fresh_mfa_and_never_reveal_whether_an_account_exists(): void
    {
        Mail::fake();
        $f = $this->agency();
        $this->api($f['olive'], $f['ws'], 'POST', 'invitations', ['email' => 'x@test.local', 'role' => 'technician'])
            ->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        // Same response for an existing account and a brand-new email:
        $this->api($f['olive'], $f['ws'], 'POST', 'invitations', ['email' => 'tina@test.local', 'role' => 'billing'], mfaFresh: true)->assertCreated();
        $this->api($f['olive'], $f['ws'], 'POST', 'invitations', ['email' => 'nobody@test.local', 'role' => 'billing'], mfaFresh: true)->assertCreated();
        $this->api($f['olive'], $f['ws'], 'POST', 'invitations', ['email' => 'nobody@test.local', 'role' => 'billing'], mfaFresh: true)->assertStatus(409);
        Mail::assertSent(InvitationMail::class, 2);
        $this->assertSame(0, $this->owner()->table('audit_events')->where('action', 'like', 'member.%')->count());
    }

    public function test_accepting_an_invitation_end_to_end(): void
    {
        Mail::fake();
        $f = $this->agency();
        $this->api($f['olive'], $f['ws'], 'POST', 'invitations',
            ['email' => 'newbie@test.local', 'role' => 'client_staff', 'client_id' => $f['client']], mfaFresh: true)->assertCreated();
        $link = null;
        Mail::assertSent(InvitationMail::class, function ($m) use (&$link) { $link = $m->link; return $m->hasTo('newbie@test.local'); });
        $token = $this->linkToken($link);

        $wrong = $this->makeUser('someone');
        $this->api($wrong, null, 'POST', 'invitations/accept', ['token' => $token])->assertForbidden()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'different email'));

        $newbie = $this->makeUser('newbie', verified: false);
        $this->api($newbie, null, 'POST', 'invitations/accept', ['token' => $token])->assertForbidden()->assertJsonPath('code', 'email_unverified');
        $this->owner()->update('UPDATE users SET email_verified_at = now() WHERE id = ?', [$newbie->id]);
        $this->api($newbie, null, 'POST', 'invitations/accept', ['token' => $token])->assertOk()->assertJsonPath('workspace_id', $f['ws']);
        $this->api($newbie, null, 'POST', 'invitations/accept', ['token' => $token])->assertStatus(422);

        $this->api($newbie, null, 'GET', 'workspaces')->assertJsonPath('data.0.role', 'client_staff');
    }

    public function test_client_owner_can_invite_staff_for_their_client_only(): void
    {
        Mail::fake();
        $f = $this->agency();
        $this->enableMfa($f['carl']);
        $this->api($f['carl'], $f['ws'], 'POST', 'invitations', ['email' => 's@test.local', 'role' => 'client_staff', 'client_id' => $f['client']], mfaFresh: true)->assertCreated();
        $this->api($f['carl'], $f['ws'], 'POST', 'invitations', ['email' => 't@test.local', 'role' => 'technician'], mfaFresh: true)->assertForbidden();
    }

    // ------------------------------------------------------------- members
    public function test_suspending_a_member_cuts_their_access_immediately(): void
    {
        $f = $this->agency();
        [$site] = $this->makeSite($f['ws'], null, 'own.example');
        $this->api($f['adam'], $f['ws'], 'GET', 'websites')->assertOk()->assertJsonCount(1, 'data');
        $this->api($f['olive'], $f['ws'], 'PATCH', "members/{$f['adam']->id}", ['active' => false], mfaFresh: true)->assertNoContent();
        $this->api($f['adam'], $f['ws'], 'GET', 'websites')->assertNotFound();
        $this->assertSame('member.suspended', $this->auditRows('member.suspended')[0]->action);
        $this->api($f['olive'], $f['ws'], 'GET', 'members')->assertJsonPath('data.3.email', 'adam@test.local')   // suspended members listed last
            ->assertJsonPath('data.3.suspended_at', fn ($v) => $v !== null);
    }

    public function test_owner_protection_and_admin_limits(): void
    {
        $f = $this->agency();
        $this->enableMfa($f['adam']);
        $this->api($f['adam'], $f['ws'], 'PATCH', "members/{$f['olive']->id}", ['active' => false], mfaFresh: true)->assertForbidden();
        $this->api($f['adam'], $f['ws'], 'PATCH', "members/{$f['tina']->id}", ['role' => 'owner'], mfaFresh: true)->assertForbidden();
        $this->api($f['olive'], $f['ws'], 'PATCH', "members/{$f['olive']->id}", ['role' => 'admin'], mfaFresh: true)
            ->assertStatus(422)->assertJsonPath('message', 'A workspace must keep at least one owner.');
        $this->api($f['tina'], $f['ws'], 'PATCH', "members/{$f['carl']->id}", ['active' => false], mfaFresh: true)->assertForbidden();
    }
}
