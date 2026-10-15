<?php

namespace Tests\Feature;

use Tests\TestCase;

/** BRD-001/002/003, MON-007, §18.2 workflow, SVC-003 approvals, payer rule. */
class BoardAndTicketTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $f = [];
        $f['alice'] = $this->makeUser('alice');
        $f['tina'] = $this->makeUser('tina');
        $f['carl'] = $this->makeUser('carl');
        $f['carlUnverified'] = $this->makeUser('carl2', verified: false);
        $f['ws'] = $this->makeWorkspace('Agency A');
        $this->addMember($f['ws'], $f['alice'], 'owner');
        $this->addMember($f['ws'], $f['tina'], 'technician');
        $this->addMember($f['ws'], $f['carl'], 'client_owner');
        $this->addMember($f['ws'], $f['carlUnverified'], 'client_owner');
        $f['client'] = $this->makeClient($f['ws'], 'Mitra Traders');
        $this->assign($f['ws'], $f['client'], $f['tina']);
        $this->assign($f['ws'], $f['client'], $f['carl']);
        $this->assign($f['ws'], $f['client'], $f['carlUnverified']);
        [$f['site'], $f['target']] = $this->makeSite($f['ws'], $f['client'], 'shop.mitra.example');
        $this->f = $f;
    }

    public function test_ac_brd_001_1_one_critical_site_makes_the_client_critical(): void
    {
        $f = $this->f;
        [, $t2] = $this->makeSite($f['ws'], $f['client'], 'mitra.example');
        $this->observeAll($f['ws'], $f['target'], 'ok');
        $this->observe($f['ws'], $t2, 'http', 'critical');
        $this->observe($f['ws'], $t2, 'tls', 'ok');
        $this->observe($f['ws'], $t2, 'dns', 'ok');

        $r = $this->api($f['alice'], $f['ws'], 'GET', 'boards/clients')->assertOk();
        $r->assertJsonPath('data.0.lane', 'critical')->assertJsonPath('data.0.impacted_count', 1);
    }

    public function test_ac_mon_007_1_stale_readings_make_the_site_unknown_not_healthy(): void
    {
        $f = $this->f;
        $this->observeAll($f['ws'], $f['target'], 'ok', minutesAgo: 16); // > 3 × 5 min
        $this->api($f['alice'], $f['ws'], 'GET', 'boards/websites')->assertOk()
            ->assertJsonPath('data.0.lane', 'unknown')
            ->assertJsonPath('data.0.last_verified_at', null)
            ->assertJsonPath('data.0.checks.0.freshness', 'stale');
    }

    public function test_fresh_ok_readings_are_healthy_with_last_verified_time(): void
    {
        $f = $this->f;
        $this->observeAll($f['ws'], $f['target'], 'ok', minutesAgo: 2);
        $r = $this->api($f['alice'], $f['ws'], 'GET', 'boards/websites')->assertOk()->assertJsonPath('data.0.lane', 'healthy');
        $this->assertNotNull($r->json('data.0.last_verified_at'));
    }

    public function test_ac_brd_003_2_investigating_to_ready_for_work_is_rejected(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating');
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'ready_for_work', 'version' => 2])
            ->assertStatus(422)->assertJsonPath('message', "Investigating can't move to Ready for work.");
        $this->assertSame('denied', $this->auditRows('ticket.transition')[0]->outcome);
    }

    public function test_ac_brd_003_1_resolved_needs_passed_verification(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating', 'quote_pending');
        $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$t}/approvals", ['amount_paise' => 0, 'action_fingerprint' => 'x'])->assertCreated();
        $this->owner()->update("UPDATE tickets SET state = 'ready_for_work', version = version + 1 WHERE id = ?", [$t]);
        $this->owner()->update("UPDATE tickets SET state = 'in_progress', version = version + 1 WHERE id = ?", [$t]);
        $this->owner()->update("UPDATE tickets SET state = 'verifying', version = version + 1 WHERE id = ?", [$t]);
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'resolved', 'version' => 6])
            ->assertStatus(422)->assertJsonPath('message', 'Post-fix verification has not passed.');
    }

    public function test_ac_svc_001_1_stale_version_gets_409(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site']);
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'investigating', 'version' => 1])->assertOk();
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'waiting_for_customer', 'version' => 1])
            ->assertStatus(409);
    }

    public function test_client_owner_cannot_move_tickets(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site']);
        $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'investigating', 'version' => 1])
            ->assertForbidden();
    }

    public function test_ac_svc_003_3_zero_rupee_work_still_needs_client_approval(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating', 'quote_pending');
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'ready_for_work', 'version' => 3])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'needs a current customer approval'));

        $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$t}/approvals",
            ['amount_paise' => 0, 'action_fingerprint' => 'tls:renew:shop.mitra.example'])->assertCreated();
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'ready_for_work', 'version' => 3])
            ->assertOk()->assertJsonPath('state', 'ready_for_work');

        $events = $this->owner()->select('SELECT from_state, to_state FROM ticket_events WHERE ticket_id = ?', [$t]);
        $this->assertSame('ready_for_work', end($events)->to_state);
        $this->assertSame('allowed', $this->auditRows('approval.created')[0]->outcome);
    }

    public function test_paid_work_waits_for_verified_payment(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating', 'quote_pending');
        $this->api($f['carl'], $f['ws'], 'POST', "tickets/{$t}/approvals",
            ['amount_paise' => 10000, 'action_fingerprint' => 'dns:a-record'])->assertCreated();
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'ready_for_work', 'version' => 3])
            ->assertStatus(422);
    }

    public function test_ac_svc_003_4_agency_owner_cannot_approve_client_work_without_contract(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating', 'quote_pending');
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/approvals", ['amount_paise' => 0, 'action_fingerprint' => 'x'])
            ->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'client contract'));

        $this->owner()->update('UPDATE clients SET agency_may_pay = true WHERE id = ?', [$f['client']]);
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/approvals", ['amount_paise' => 0, 'action_fingerprint' => 'x'])
            ->assertCreated();
    }

    public function test_technician_can_never_approve(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating', 'quote_pending');
        $this->api($f['tina'], $f['ws'], 'POST', "tickets/{$t}/approvals", ['amount_paise' => 0, 'action_fingerprint' => 'x'])
            ->assertForbidden();
    }

    public function test_ac_acc_001_2_unverified_email_cannot_approve(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site'], 'investigating', 'quote_pending');
        $this->api($f['carlUnverified'], $f['ws'], 'POST', "tickets/{$t}/approvals", ['amount_paise' => 0, 'action_fingerprint' => 'x'])
            ->assertForbidden()->assertJsonPath('message', 'Verify your email address before approving work.');
    }

    public function test_failed_request_leaves_no_partial_writes(): void
    {
        $f = $this->f;
        // Ticket creation writes a ticket + event, then the illegal second step must not exist.
        $t = $this->makeTicket($f['ws'], $f['site']);
        $before = $this->owner()->table('ticket_events')->count();
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'closed', 'version' => 1])->assertStatus(422);
        $this->assertSame($before, $this->owner()->table('ticket_events')->count());
        $this->assertSame('new', $this->owner()->table('tickets')->where('id', $t)->value('state'));
    }

    public function test_ac_acc_007_1_audit_rows_cannot_be_changed(): void
    {
        $f = $this->f;
        $t = $this->makeTicket($f['ws'], $f['site']);
        $this->api($f['alice'], $f['ws'], 'POST', "tickets/{$t}/transition", ['to' => 'investigating', 'version' => 1])->assertOk();
        $row = $this->auditRows('ticket.transition')[0];
        $this->assertSame($f['alice']->id, $row->actor_id);
        $this->expectExceptionMessage('append-only');
        $this->owner()->update("UPDATE audit_events SET outcome = 'denied' WHERE id = ?", [$row->id]);
    }
}
