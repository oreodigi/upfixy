<?php

namespace Tests\Feature;

use Tests\TestCase;

/** M2: overview (SCR-02), client and website detail (SCR-06/07), assignment, saved views (BRD-004). */
class BoardsM2Test extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $f = [];
        foreach (['olive', 'tina', 'tom', 'carl', 'cara'] as $n) $f[$n] = $this->makeUser($n);
        $f['ws'] = $this->makeWorkspace('Agency');
        $this->addMember($f['ws'], $f['olive'], 'owner');
        $this->addMember($f['ws'], $f['tina'], 'technician');
        $this->addMember($f['ws'], $f['tom'], 'technician');
        $this->addMember($f['ws'], $f['carl'], 'client_owner');
        $this->addMember($f['ws'], $f['cara'], 'client_owner');
        $f['mitra'] = $this->makeClient($f['ws'], 'Mitra');
        $f['other'] = $this->makeClient($f['ws'], 'Other');
        $this->assign($f['ws'], $f['mitra'], $f['tina']);
        $this->assign($f['ws'], $f['mitra'], $f['carl']);
        $this->assign($f['ws'], $f['other'], $f['tom']);
        $this->assign($f['ws'], $f['other'], $f['cara']);
        [$f['shop'], $f['shopT']] = $this->makeSite($f['ws'], $f['mitra'], 'shop.mitra.example');
        [$f['blog'], $f['blogT']] = $this->makeSite($f['ws'], $f['other'], 'blog.other.example');
        $this->observe($f['ws'], $f['shopT'], 'http', 'critical');
        $this->observe($f['ws'], $f['shopT'], 'tls', 'warning');
        $this->observe($f['ws'], $f['shopT'], 'dns', 'ok');
        $this->observeAll($f['ws'], $f['blogT'], 'ok');
        $f['ticket'] = $this->makeTicket($f['ws'], $f['shop'], 'investigating');
        $this->f = $f;
    }

    public function test_overview_counts_only_what_the_caller_can_see(): void
    {
        $f = $this->f;
        $this->api($f['olive'], $f['ws'], 'GET', 'overview')->assertOk()
            ->assertJsonPath('websites.total', 2)
            ->assertJsonPath('websites.by_lane.critical', 1)
            ->assertJsonPath('websites.by_lane.healthy', 1)
            ->assertJsonPath('issues.0.severity', 'critical')   // worst first
            ->assertJsonPath('issues.0.kind', 'http')
            ->assertJsonPath('issues.1.severity', 'warning')
            ->assertJsonPath('requests.by_state.investigating', 1);
        // Cara (other client) sees one healthy site and none of Mitra's issues.
        $this->api($f['cara'], $f['ws'], 'GET', 'overview')->assertOk()
            ->assertJsonPath('websites.total', 1)->assertJsonPath('issues', [])
            ->assertJsonPath('requests.by_state', []);
    }

    public function test_client_detail_shows_staff_and_client_users_to_the_owner(): void
    {
        $f = $this->f;
        $r = $this->api($f['olive'], $f['ws'], 'GET', "clients/{$f['mitra']}")->assertOk()
            ->assertJsonPath('lane', 'critical')->assertJsonPath('open_requests', 1)
            ->assertJsonPath('staff.0.email', 'tina@test.local')
            ->assertJsonPath('client_users.0.email', 'carl@test.local');
        $this->assertCount(1, $r->json('websites'));
        $this->api($f['cara'], $f['ws'], 'GET', "clients/{$f['mitra']}")->assertNotFound();
    }

    public function test_board_clients_lists_assigned_technicians(): void
    {
        $f = $this->f;
        $rows = collect($this->api($f['olive'], $f['ws'], 'GET', 'boards/clients')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame('tina@test.local', $rows['Mitra']['staff'][0]['email']);
        $this->assertSame(1, $rows['Mitra']['open_requests']);
    }

    public function test_website_detail_and_history(): void
    {
        $f = $this->f;
        $this->api($f['carl'], $f['ws'], 'GET', "websites/{$f['shop']}")->assertOk()
            ->assertJsonPath('client_name', 'Mitra')->assertJsonPath('lane', 'critical')
            ->assertJsonPath('targets.0.host', 'shop.mitra.example')
            ->assertJsonPath('requests.0.state', 'investigating')
            ->assertJsonPath('verified_hosts', []);
        $this->api($f['carl'], $f['ws'], 'GET', "websites/{$f['shop']}/history?kind=tls")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.severity', 'warning');
        $this->api($f['carl'], $f['ws'], 'GET', "websites/{$f['shop']}/history?limit=999")->assertStatus(422);
    }

    public function test_history_hides_private_readings_until_ownership_is_proven(): void
    {
        $f = $this->f;
        $this->owner()->insert("INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, source, result_code, observed_at)
            VALUES (?, ?, 'tls', 2, 'ok', 'connector', 'AUTOSSL_OK', now())", [$f['ws'], $f['shopT']]);
        $sources = fn () => collect($this->api($f['olive'], $f['ws'], 'GET', "websites/{$f['shop']}/history")->json('data'))->pluck('source')->unique()->values()->all();
        $this->assertSame(['public'], $sources());
        $this->verifyOwnership($f['olive'], $f['ws'], $f['shop'], 'shop.mitra.example');
        $this->assertEqualsCanonicalizing(['public', 'connector'], $sources());
    }

    public function test_assignment_rules(): void
    {
        $f = $this->f;
        $t = $f['ticket'];
        $this->api($f['olive'], $f['ws'], 'GET', "tickets/{$t}/assignable")->assertOk()
            ->assertJsonCount(2, 'data');                                   // olive (owner) + tina (Mitra's technician)
        $this->api($f['olive'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => $f['tom']->id, 'version' => 2])
            ->assertStatus(422)->assertJsonPath('message', "That technician isn't assigned to this client.");
        $this->api($f['olive'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => $f['carl']->id, 'version' => 2])
            ->assertStatus(422)->assertJsonPath('message', 'Requests can only be assigned to active staff.');
        $this->api($f['olive'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => $f['tina']->id, 'version' => 2])
            ->assertOk()->assertJsonPath('version', 3);
        $this->api($f['olive'], $f['ws'], 'GET', 'tickets')->assertJsonPath('data.0.assignee_email', 'tina@test.local');
        // Stale version:
        $this->api($f['olive'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => null, 'version' => 2])->assertStatus(409);
        // Technicians take or drop only themselves:
        $this->api($f['tina'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => $f['olive']->id, 'version' => 3])->assertForbidden();
        $this->api($f['tina'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => null, 'version' => 3])->assertOk();
        // Client owners never assign:
        $this->api($f['carl'], $f['ws'], 'PATCH', "tickets/{$t}/assignee", ['assignee_id' => null, 'version' => 4])->assertForbidden();
        $this->assertSame('allowed', $this->auditRows('ticket.assigned')[0]->outcome);
    }

    public function test_suspending_a_technician_unassigns_their_requests(): void
    {
        $f = $this->f;
        $this->api($f['olive'], $f['ws'], 'PATCH', "tickets/{$f['ticket']}/assignee", ['assignee_id' => $f['tina']->id, 'version' => 2])->assertOk();
        $this->enableMfa($f['olive']);
        $this->api($f['olive'], $f['ws'], 'PATCH', "members/{$f['tina']->id}", ['active' => false], mfaFresh: true)->assertNoContent();
        $this->assertNull($this->owner()->table('tickets')->where('id', $f['ticket'])->value('assignee_id'));
    }

    public function test_removing_a_technician_from_a_client_frees_their_requests(): void
    {
        $f = $this->f;
        $this->api($f['olive'], $f['ws'], 'PATCH', "tickets/{$f['ticket']}/assignee", ['assignee_id' => $f['tina']->id, 'version' => 2])->assertOk();
        $this->api($f['tina'], $f['ws'], 'DELETE', "clients/{$f['mitra']}/assignments/{$f['tina']->id}")->assertForbidden();
        $this->api($f['olive'], $f['ws'], 'DELETE', "clients/{$f['mitra']}/assignments/{$f['tina']->id}")->assertNoContent();
        $this->assertNull($this->owner()->table('tickets')->where('id', $f['ticket'])->value('assignee_id'));
        $this->api($f['olive'], $f['ws'], 'DELETE', "clients/{$f['mitra']}/assignments/{$f['tina']->id}")->assertNotFound();
        // Tina no longer sees Mitra's websites:
        $this->api($f['tina'], $f['ws'], 'GET', "websites/{$f['shop']}")->assertNotFound();
    }

    public function test_saved_views_are_personal(): void
    {
        $f = $this->f;
        $id = $this->api($f['olive'], $f['ws'], 'POST', 'views', ['board' => 'websites', 'name' => 'Needs attention',
            'filters' => ['lanes' => ['critical', 'warning'], 'q' => 'shop', 'evil' => 'x']])->assertCreated()->json('id');
        $this->api($f['olive'], $f['ws'], 'POST', 'views', ['board' => 'websites', 'name' => 'Needs attention', 'filters' => []])->assertStatus(409);
        $this->api($f['olive'], $f['ws'], 'POST', 'views', ['board' => 'websites', 'name' => 'Bad', 'filters' => ['lanes' => ['on_fire']]])->assertStatus(422);

        $filters = $this->api($f['olive'], $f['ws'], 'GET', 'views?board=websites')->assertOk()->json('data.0.filters');
        $this->assertEquals(['lanes' => ['critical', 'warning'], 'q' => 'shop'], $filters); // unknown keys dropped (jsonb reorders keys)
        // Another member of the same workspace can't see, change or delete it.
        $this->api($f['tina'], $f['ws'], 'GET', 'views')->assertOk()->assertJsonCount(0, 'data');
        $this->api($f['tina'], $f['ws'], 'PATCH', "views/{$id}", ['name' => 'Mine now'])->assertNotFound();
        $this->api($f['tina'], $f['ws'], 'DELETE', "views/{$id}")->assertNotFound();

        $this->api($f['olive'], $f['ws'], 'PATCH', "views/{$id}", ['name' => 'Urgent'])->assertNoContent();
        $this->api($f['olive'], $f['ws'], 'DELETE', "views/{$id}")->assertNoContent();
        $this->api($f['olive'], $f['ws'], 'GET', 'views')->assertJsonCount(0, 'data');
    }

    public function test_saved_views_are_capped_per_board(): void
    {
        $f = $this->f;
        for ($i = 1; $i <= 20; $i++) {
            $this->api($f['olive'], $f['ws'], 'POST', 'views', ['board' => 'work', 'name' => "View {$i}", 'filters' => ['assignee' => 'me']])->assertCreated();
        }
        $this->api($f['olive'], $f['ws'], 'POST', 'views', ['board' => 'work', 'name' => 'One too many', 'filters' => []])
            ->assertStatus(422)->assertJsonPath('message', 'You can keep up to 20 views per board. Delete one first.');
    }

    public function test_guests_get_401_json_even_without_an_accept_header(): void
    {
        $this->call('GET', '/api/v1/auth/me')->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }
}
