<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Route-driven isolation sweep (AC-ACC-003.1, NFR-12).
 *
 * Every /api/v1 route with an ID parameter is called by an attacker holding
 * the victim's REAL ids and a VALID request body, with fresh MFA, as an owner
 * of their own workspace. Each call must answer like a missing resource, and
 * no row anywhere in the victim's data may change. A new route whose parameter
 * isn't mapped below fails this test until it is added, so coverage can't
 * silently fall behind the API.
 */
class CrossTenantSweepTest extends TestCase
{
    private array $v = [];

    /** Tables whose contents must be byte-identical before and after the sweep. */
    private const WATCHED = ['workspaces', 'memberships', 'clients', 'client_assignments', 'websites', 'monitor_targets',
        'ownership_claims', 'delegations', 'observations', 'tickets', 'ticket_events', 'quotes', 'approvals', 'invitations', 'users',
        'saved_views'];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Victim: a fully populated agency workspace.
        $v = [];
        $v['alice'] = $this->makeUser('alice');
        $v['carl'] = $this->makeUser('carl');
        $v['ws'] = $this->makeWorkspace('Victim Agency');
        $this->addMember($v['ws'], $v['alice'], 'owner');
        $this->addMember($v['ws'], $v['carl'], 'client_owner');
        $v['client'] = $this->makeClient($v['ws'], 'Victim Client Pvt Ltd');
        $this->assign($v['ws'], $v['client'], $v['carl']);
        [$v['site'], $v['target']] = $this->makeSite($v['ws'], $v['client'], 'victim-secret.example');
        $v['claim'] = $this->verifyOwnership($v['alice'], $v['ws'], $v['site'], 'victim-secret.example');
        $v['ticket'] = $this->makeTicket($v['ws'], $v['site'], 'investigating');
        $q = $this->api($v['alice'], $v['ws'], 'POST', "tickets/{$v['ticket']}/quotes",
            ['monitor_target_id' => $v['target'], 'action_code' => 'provider.covered_fix', 'description' => 'Victim work'])->assertCreated();
        $v['quote'] = $q->json('id');
        $v['fingerprint'] = $q->json('fingerprint');
        $this->enableMfa($v['alice']);
        $v['invitation'] = $this->api($v['alice'], $v['ws'], 'POST', 'invitations',
            ['email' => 'pending@test.local', 'role' => 'technician'], mfaFresh: true)->assertCreated()->json('id');
        $v['view'] = $this->owner()->selectOne(
            "INSERT INTO saved_views(workspace_id, user_id, board, name, filters) VALUES (?, ?, 'websites', 'Victim view', '{\"q\":\"victim\"}') RETURNING id",
            [$v['ws'], $v['alice']->id])->id;
        $this->v = $v;
    }

    /** Valid bodies for each route, using the victim's ids wherever an id is referenced. */
    private function body(string $method, string $uri, array $attacker): array
    {
        $v = $this->v;
        return match ("{$method} {$uri}") {
            'PATCH api/v1/members/{user}' => ['active' => false],
            'POST api/v1/invitations/{invitation}/revoke' => [],
            'PATCH api/v1/clients/{client}' => ['name' => 'Renamed by attacker', 'agency_may_pay' => true],
            'POST api/v1/clients/{client}/assignments' => ['user_id' => $attacker['user']->id],
            'GET api/v1/websites/{website}', 'GET api/v1/websites/{website}/claims', 'GET api/v1/tickets/{ticket}' => [],
            'POST api/v1/websites/{website}/claims' => ['host' => 'victim-secret.example', 'method' => 'dns_txt'],
            'POST api/v1/claims/{claim}/check', 'POST api/v1/claims/{claim}/revoke' => [],
            'POST api/v1/tickets/{ticket}/transition' => ['to' => 'closed', 'version' => 2],
            'POST api/v1/tickets/{ticket}/quotes' => ['monitor_target_id' => $v['target'], 'action_code' => 'provider.covered_fix', 'description' => 'x'],
            'POST api/v1/quotes/{quote}/void' => ['reason' => 'attacker'],
            'POST api/v1/tickets/{ticket}/approvals' => ['quote_id' => $v['quote'], 'fingerprint' => $v['fingerprint']],
            'GET api/v1/clients/{client}', 'GET api/v1/websites/{website}/history', 'GET api/v1/tickets/{ticket}/assignable' => [],
            'PATCH api/v1/tickets/{ticket}/assignee' => ['assignee_id' => $attacker['user']->id, 'version' => 2],
            'PATCH api/v1/views/{view}' => ['name' => 'Taken over'],
            'DELETE api/v1/views/{view}' => [],
            'DELETE api/v1/clients/{client}/assignments/{user}' => [],
            default => $this->fail("Route {$method} {$uri} is not covered by the cross-tenant sweep. Add a body for it."),
        };
    }

    private function param(string $name): string
    {
        return match ($name) {
            'user' => $this->v['alice']->id,
            'website' => $this->v['site'],
            'client' => $this->v['client'],
            'claim' => $this->v['claim'],
            'ticket' => $this->v['ticket'],
            'quote' => $this->v['quote'],
            'invitation' => $this->v['invitation'],
            'view' => $this->v['view'],
            default => $this->fail("Unknown route parameter {{$name}}: map it to a victim id in the sweep."),
        };
    }

    /** @return array<int, array{0: string, 1: string}> [method, uri] for every v1 route with parameters */
    private function idRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (!str_starts_with($r->uri(), 'api/v1/') || !str_contains($r->uri(), '{')) continue;
            foreach ($r->methods() as $m) {
                if (!in_array($m, ['HEAD', 'OPTIONS'], true)) $out[] = [$m, $r->uri()];
            }
        }
        return $out;
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (self::WATCHED as $t) {
            $out[$t] = $this->owner()->selectOne("SELECT count(*) AS n, md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), '')) AS h FROM {$t} x");
        }
        return $out;
    }

    private function sweep(array $attacker, array $allowedStatuses): void
    {
        $routes = $this->idRoutes();
        $this->assertGreaterThanOrEqual(20, count($routes), 'route discovery looks broken');
        $before = $this->snapshot();
        $secrets = ['Victim Client Pvt Ltd', 'victim-secret.example', 'Victim work', 'alice@test.local', 'pending@test.local',
            'Victim view', $this->v['fingerprint']];

        foreach ($routes as [$method, $uri]) {
            $path = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $this->param($m[1]), substr($uri, strlen('api/v1/')));
            $res = $this->api($attacker['user'], $attacker['ws'], $method, $path, $this->body($method, $uri, $attacker), mfaFresh: true);
            $this->assertContains($res->status(), $allowedStatuses, "{$method} {$uri} answered {$res->status()}: " . $res->getContent());
            foreach ($secrets as $s) {
                $this->assertStringNotContainsString($s, $res->getContent(), "{$method} {$uri} leaked victim data");
            }
        }

        $after = $this->snapshot();
        foreach (self::WATCHED as $t) {
            $this->assertEquals($before[$t], $after[$t], "table {$t} changed during the sweep");
        }
    }

    public function test_another_workspace_owner_gets_404_everywhere_and_changes_nothing(): void
    {
        $bob = $this->makeUser('bob');
        $ws = $this->makeWorkspace('Attacker Agency');
        $this->addMember($ws, $bob, 'owner');
        $this->enableMfa($bob);
        $this->sweep(['user' => $bob, 'ws' => $ws], [404]);
        // Every probe was logged as a denied access in the attacker's own tenant, never the victim's.
        $rows = $this->auditRows('resource.not_found');
        $this->assertGreaterThanOrEqual(14, count($rows));
        foreach ($rows as $r) $this->assertSame($ws, $r->workspace_id);
    }

    public function test_another_client_in_the_same_workspace_sees_and_changes_nothing(): void
    {
        $cara = $this->makeUser('cara');
        $this->addMember($this->v['ws'], $cara, 'client_owner');
        $other = $this->makeClient($this->v['ws'], 'Other Client');
        $this->assign($this->v['ws'], $other, $cara);
        $this->enableMfa($cara);
        // Role checks may answer 403 before lookup; neither reveals the resource.
        $this->sweep(['user' => $cara, 'ws' => $this->v['ws']], [403, 404]);
    }

    public function test_body_references_to_another_workspace_are_refused_without_writes(): void
    {
        $bob = $this->makeUser('bob');
        $ws = $this->makeWorkspace('Attacker Agency');
        $this->addMember($ws, $bob, 'owner');
        [$ownSite] = $this->makeSite($ws, null, 'own.example');
        $ownTicket = $this->makeTicket($ws, $ownSite, 'investigating');
        $this->enableMfa($bob);
        $before = $this->snapshot();

        $this->api($bob, $ws, 'POST', 'tickets', ['website_id' => $this->v['site'], 'title' => 'x'])->assertNotFound();
        $this->api($bob, $ws, 'POST', 'websites', ['url' => 'new.example', 'client_id' => $this->v['client']])->assertStatus(422);
        $this->api($bob, $ws, 'POST', "tickets/{$ownTicket}/quotes",
            ['monitor_target_id' => $this->v['target'], 'action_code' => 'provider.covered_fix', 'description' => 'x'])->assertStatus(422);
        $this->api($bob, $ws, 'POST', 'invitations', ['email' => 'z@test.local', 'role' => 'client_staff', 'client_id' => $this->v['client']], mfaFresh: true)
            ->assertStatus(422);

        $after = $this->snapshot();
        foreach (self::WATCHED as $t) {
            if (in_array($t, ['websites', 'monitor_targets', 'tickets', 'ticket_events', 'quotes', 'invitations'], true)) {
                // The attacker's own rows may not change either: every one of these requests failed.
                $this->assertEquals($before[$t], $after[$t], "table {$t} changed");
            }
        }
    }
}
