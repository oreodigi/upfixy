<?php

namespace Tests\Feature;

use Tests\TestCase;

/** SVC-002/003, FIX-A, FIX-B, D-07: quote-bound approvals through the API. */
class QuoteApprovalTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $f = [];
        foreach (['olive', 'tina', 'carl', 'cara'] as $n) $f[$n] = $this->makeUser($n);
        $f['carlUnverified'] = $this->makeUser('carl2', verified: false);
        $f['ws'] = $this->makeWorkspace('Agency A');
        $this->addMember($f['ws'], $f['olive'], 'owner');
        $this->addMember($f['ws'], $f['tina'], 'technician');
        $this->addMember($f['ws'], $f['carl'], 'client_owner');
        $this->addMember($f['ws'], $f['cara'], 'client_owner');
        $this->addMember($f['ws'], $f['carlUnverified'], 'client_owner');
        $f['client'] = $this->makeClient($f['ws'], 'Mitra Traders');
        $f['other'] = $this->makeClient($f['ws'], 'Other Co');
        foreach (['tina', 'carl', 'carlUnverified'] as $n) $this->assign($f['ws'], $f['client'], $f[$n]);
        $this->assign($f['ws'], $f['other'], $f['cara']);
        [$f['site'], $f['target']] = $this->makeSite($f['ws'], $f['client'], 'shop.mitra.example');
        $f['ticket'] = $this->makeTicket($f['ws'], $f['site'], 'investigating');
        $this->f = $f;
    }

    private function quote(string $action = 'provider.covered_fix', array $extra = [])
    {
        $f = $this->f;
        return $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/quotes", [
            'monitor_target_id' => $f['target'], 'action_code' => $action, 'description' => 'Renew SSL certificate', ...$extra,
        ]);
    }

    private function toQuotePending(): void
    {
        $this->api($this->f['tina'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/transition", ['to' => 'quote_pending', 'version' => 2])->assertOk();
    }

    private function approve($user, $quote, ?string $fingerprint = null, bool $mfaFresh = false)
    {
        return $this->api($user, $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/approvals",
            ['quote_id' => $quote->json('id'), 'fingerprint' => $fingerprint ?? $quote->json('fingerprint')], $mfaFresh);
    }

    private function startWork()
    {
        return $this->api($this->f['tina'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/transition", ['to' => 'ready_for_work', 'version' => 3]);
    }

    public function test_price_comes_from_the_catalogue_not_the_request(): void
    {
        $q = $this->quote('dns.record_fix', ['amount_paise' => 1])->assertCreated();
        $q->assertJsonPath('amount_paise', 10000)->assertJsonPath('action.code', 'dns.record_fix')
          ->assertJsonPath('target.host', 'shop.mitra.example')->assertJsonPath('ownership_verified', false);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $q->json('fingerprint'));
    }

    public function test_unknown_actions_and_client_users_are_refused(): void
    {
        $this->quote('server.root_shell')->assertStatus(422);
        $f = $this->f;
        $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$f['ticket']}/quotes", [
            'monitor_target_id' => $f['target'], 'action_code' => 'provider.covered_fix', 'description' => 'x',
        ])->assertForbidden();
    }

    public function test_ac_ast_002_7_approval_refused_until_ownership_is_verified(): void
    {
        $q = $this->quote()->assertCreated();
        $this->toQuotePending();
        $this->approve($this->f['carl'], $q)->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Verify ownership'));
    }

    public function test_ac_svc_003_3_full_zero_rupee_flow_needs_ownership_quote_and_client_approval(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote()->assertCreated()->assertJsonPath('ownership_verified', true);
        $this->toQuotePending();
        $this->startWork()->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'needs a current customer approval'));

        $this->approve($f['carl'], $q)->assertCreated();
        $this->startWork()->assertOk()->assertJsonPath('state', 'ready_for_work');
        $this->assertSame('allowed', $this->auditRows('approval.created')[0]->outcome);
    }

    public function test_a_changed_quote_cannot_be_approved_with_the_old_fingerprint(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q1 = $this->quote()->assertCreated();
        $this->toQuotePending();
        $q2 = $this->quote('provider.covered_fix', ['description' => 'Different scope'])->assertCreated();
        // Approving the superseded quote:
        $this->approve($f['carl'], $q1)->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'expired or been replaced'));
        // Current quote id, but a fingerprint from what the client saw earlier:
        $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$f['ticket']}/approvals",
            ['quote_id' => $q2->json('id'), 'fingerprint' => $q1->json('fingerprint')])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'changed after you opened it'));
        $this->approve($f['carl'], $q2)->assertCreated();
    }

    public function test_paid_approval_needs_step_up_mfa_and_work_still_waits_for_payment(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote('cpanel.ssl_fix')->assertCreated()->assertJsonPath('amount_paise', 20000);
        $this->toQuotePending();
        $this->approve($f['carl'], $q)->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
        $this->enableMfa($f['carl']);
        $this->approve($f['carl'], $q)->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->approve($f['carl'], $q, mfaFresh: true)->assertCreated();
        $this->startWork()->assertStatus(422);
        $this->api($f['tina'], $f['ws'], 'GET', "tickets/{$f['ticket']}")->assertJsonPath('quote.approved', true)
            ->assertJsonPath('quote.payment_required', true);
    }

    public function test_ac_svc_003_4_payer_rule(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote()->assertCreated();
        $this->toQuotePending();
        $this->approve($f['tina'], $q)->assertForbidden();        // technician: never
        $this->approve($f['cara'], $q)->assertNotFound();         // another client's owner: can't even see it
        $this->approve($f['olive'], $q)->assertForbidden()        // agency owner: only if the contract delegates
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'client contract'));
    }

    public function test_agency_payment_authority_needs_the_owner_with_fresh_mfa(): void
    {
        $f = $this->f;
        $this->api($f['olive'], $f['ws'], 'PATCH', "clients/{$f['client']}", ['agency_may_pay' => true])
            ->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
        $this->enableMfa($f['olive']);
        $this->api($f['olive'], $f['ws'], 'PATCH', "clients/{$f['client']}", ['agency_may_pay' => true], mfaFresh: true)->assertNoContent();

        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote()->assertCreated();
        $this->toQuotePending();
        $this->approve($f['olive'], $q)->assertCreated();
    }

    public function test_ac_acc_001_2_unverified_email_cannot_approve(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote()->assertCreated();
        $this->toQuotePending();
        $this->approve($f['carlUnverified'], $q)->assertForbidden()
            ->assertJsonPath('message', 'Verify your email address before approving work.');
    }

    public function test_ac_brd_003_1_resolved_needs_passed_verification(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote()->assertCreated();
        $this->toQuotePending();
        $this->approve($f['carl'], $q)->assertCreated();
        $this->startWork()->assertOk();
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/transition", ['to' => 'in_progress', 'version' => 4])->assertOk();
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/transition", ['to' => 'verifying', 'version' => 5])->assertOk();
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$f['ticket']}/transition", ['to' => 'resolved', 'version' => 6])
            ->assertStatus(422)->assertJsonPath('message', 'Post-fix verification has not passed.');
    }

    public function test_voiding_a_quote_voids_its_approval(): void
    {
        $f = $this->f;
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $q = $this->quote()->assertCreated();
        $this->toQuotePending();
        $this->approve($f['carl'], $q)->assertCreated();
        $this->api($f['tina'], $f['ws'], 'POST', "quotes/{$q->json('id')}/void", ['reason' => 'Scope changed'])->assertNoContent();
        $this->startWork()->assertStatus(422);
        $this->assertNotNull($this->owner()->table('approvals')->value('voided_at'));
    }
}
