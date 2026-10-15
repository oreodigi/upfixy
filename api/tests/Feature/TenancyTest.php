<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Tenant isolation through the real API, with RLS underneath. */
class TenancyTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $f = [];
        $f['alice'] = $this->makeUser('alice');
        $f['bob'] = $this->makeUser('bob');
        $f['tina'] = $this->makeUser('tina');
        $f['carl'] = $this->makeUser('carl');
        $f['bill'] = $this->makeUser('bill');
        $f['wsA'] = $this->makeWorkspace('Agency A');
        $f['wsB'] = $this->makeWorkspace('Agency B');
        $this->addMember($f['wsA'], $f['alice'], 'owner');
        $this->addMember($f['wsA'], $f['tina'], 'technician');
        $this->addMember($f['wsA'], $f['carl'], 'client_owner');
        $this->addMember($f['wsA'], $f['bill'], 'billing');
        $this->addMember($f['wsB'], $f['bob'], 'owner');
        $f['clientX'] = $this->makeClient($f['wsA'], 'Mitra Traders');
        $f['clientY'] = $this->makeClient($f['wsA'], 'Other Co');
        $this->assign($f['wsA'], $f['clientX'], $f['tina']);
        $this->assign($f['wsA'], $f['clientX'], $f['carl']);
        [$f['siteX']] = $this->makeSite($f['wsA'], $f['clientX'], 'shop.mitra.example');
        [$f['siteY']] = $this->makeSite($f['wsA'], $f['clientY'], 'other.example');
        [$f['siteB']] = $this->makeSite($f['wsB'], null, 'b.example');
        $f['ticketY'] = $this->makeTicket($f['wsA'], $f['siteY']);
        $this->f = $f;
    }

    public function test_ac_acc_003_1_cross_tenant_read_is_404_and_audited_without_leaking(): void
    {
        $f = $this->f;
        $this->api($f['bob'], $f['wsB'], 'GET', "websites/{$f['siteX']}")->assertNotFound();
        // Indistinguishable from a website that doesn't exist at all.
        $this->api($f['bob'], $f['wsB'], 'GET', 'websites/' . '00000000-0000-4000-8000-000000000000')->assertNotFound();

        $rows = $this->auditRows('resource.not_found');
        $this->assertCount(2, $rows);
        $this->assertSame($f['bob']->id, $rows[0]->actor_id);
        $this->assertSame($f['wsB'], $rows[0]->workspace_id); // logged in Bob's own tenant, not A's
    }

    public function test_ac_acc_003_1_using_another_workspace_header_is_404_and_logged_without_tenant(): void
    {
        $f = $this->f;
        $this->api($f['bob'], $f['wsA'], 'GET', 'websites')->assertNotFound();
        $rows = $this->auditRows('resource.not_found');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->workspace_id); // never attributed to the probed workspace
    }

    public function test_ac_acc_005_1_client_owner_sees_only_their_client_sites_in_list_and_board(): void
    {
        $f = $this->f;
        $this->api($f['carl'], $f['wsA'], 'GET', 'websites')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['siteX']);
        $this->api($f['carl'], $f['wsA'], 'GET', 'boards/clients')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mitra Traders');
    }

    public function test_ac_acc_006_2_technician_cannot_read_unassigned_client_ticket_by_id(): void
    {
        $f = $this->f;
        $this->api($f['tina'], $f['wsA'], 'GET', "tickets/{$f['ticketY']}")->assertNotFound();
        $this->api($f['alice'], $f['wsA'], 'GET', "tickets/{$f['ticketY']}")->assertOk();
    }

    public function test_billing_role_sees_no_websites(): void
    {
        $f = $this->f;
        $this->api($f['bill'], $f['wsA'], 'GET', 'websites')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_technician_cannot_create_clients_and_denial_is_audited(): void
    {
        $f = $this->f;
        $this->api($f['tina'], $f['wsA'], 'POST', 'clients', ['name' => 'Sneaky'])->assertForbidden();
        $this->assertCount(1, $this->auditRows('client.create'));
        $this->assertSame('denied', $this->auditRows('client.create')[0]->outcome);
    }

    public function test_website_cannot_be_attached_to_another_workspaces_client(): void
    {
        $f = $this->f;
        $foreignClient = $this->makeClient($f['wsB'], 'B client');
        $this->api($f['alice'], $f['wsA'], 'POST', 'websites', ['url' => 'new.example', 'client_id' => $foreignClient])
            ->assertStatus(422);
        $this->assertSame(0, $this->owner()->table('websites')->where('display_name', 'new.example')->count());
    }

    public function test_workspace_routes_require_a_workspace(): void
    {
        $this->api($this->f['alice'], null, 'GET', 'websites')->assertStatus(400);
    }
}
