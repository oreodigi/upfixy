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
        'saved_views', 'check_runs', 'incidents', 'incident_events', 'notifications', 'notification_prefs',
        'diagnostic_runs', 'findings', 'ai_analyses', 'dns_connections', 'repair_drafts', 'repair_attempts', 'repair_events', 'repair_controls',
        'connectors', 'connector_assets', 'connector_events',
        'merchant_profiles', 'coverage_contracts', 'payment_orders', 'payment_receipts', 'refunds', 'payment_alerts', 'payment_webhook_inbox'];

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
        $v['coverage'] = $this->cover($v['ws'], $v['client']);
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
        // M3: an open incident with an unread alert for the victim owner.
        $v['incident'] = $this->owner()->selectOne(
            "INSERT INTO incidents(workspace_id, website_id, monitor_target_id, root_kind, result_code, opened_at, first_failure_at, last_failure_at)
             VALUES (?, ?, ?, 'http', 'HTTP_503', now(), now(), now()) RETURNING id", [$v['ws'], $v['site'], $v['target']])->id;
        $v['notification'] = $this->owner()->selectOne(
            "INSERT INTO notifications(workspace_id, user_id, incident_id, kind) VALUES (?, ?, ?, 'incident_opened') RETURNING id",
            [$v['ws'], $v['alice']->id, $v['incident']])->id;
        $this->owner()->insert('INSERT INTO notification_prefs(workspace_id, user_id, client_id, email, in_app) VALUES (?, ?, ?, false, true)',
            [$v['ws'], $v['alice']->id, $v['client']]);
        // M5: a finished diagnosis with a finding and an AI analysis.
        $this->observe($v['ws'], $v['target'], 'http', 'critical', 1, 7);
        $v['run'] = $this->owner()->selectOne(
            "INSERT INTO diagnostic_runs(workspace_id, website_id, requested_by, status, rule_version, snapshot, snapshot_hash, private_evidence, completed_at)
             VALUES (?, ?, ?, 'completed', 'rules-test', '{\"items\":[],\"rules\":{}}', 'h', false, now()) RETURNING id",
            [$v['ws'], $v['site'], $v['alice']->id])->id;
        $obs = $this->owner()->table('observations')->where('monitor_target_id', $v['target'])->value('id');
        $this->owner()->insert("INSERT INTO findings(workspace_id, run_id, source, finding_key, observed_fact, evidence_ids, confidence)
                                VALUES (?, ?, 'rules', 'observed:x', 'Victim work observed', ARRAY[?]::uuid[], 'confirmed')", [$v['ws'], $v['run'], $obs]);
        $this->owner()->insert("INSERT INTO ai_analyses(workspace_id, run_id, status, provider, prompt_version, schema_version)
                                VALUES (?, ?, 'skipped', 'none', 'p', 's')", [$v['ws'], $v['run']]);
        // M7: a DNS connection, a ready preview, a quote from it, an approval and a finished attempt.
        $k = \App\Repairs\SecretBox::keygen();
        config(['upfixy.repairs.public_key' => $k['public'], 'upfixy.repairs.keypair' => $k['keypair']]);
        $v['connection'] = $this->owner()->selectOne(
            "INSERT INTO dns_connections(workspace_id, website_id, provider, zone_id, zone_name, secret_sealed, created_by)
             VALUES (?, ?, 'cloudflare', ?, 'victim-secret.example', ?, ?) RETURNING id",
            [$v['ws'], $v['site'], str_repeat('ab', 16), \App\Repairs\SecretBox::seal('victim-cf-token-secret', $k['public']), $v['alice']->id])->id;
        $v['repair_ticket'] = $this->makeTicket($v['ws'], $v['site'], 'investigating');
        $plan = json_encode(['operation' => 'cloudflare.dns.update_address_record', 'version' => 'repair-plan-v1', 'connection_id' => $v['connection'],
            'zone_id' => str_repeat('ab', 16), 'record_id' => str_repeat('cd', 16), 'record_name' => 'victim-secret.example', 'record_type' => 'A',
            'before' => ['content' => '93.184.216.34', 'ttl' => 300, 'proxied' => false], 'after' => ['content' => '93.184.216.35', 'ttl' => 300, 'proxied' => false]]);
        $v['draft'] = $this->owner()->selectOne(
            "INSERT INTO repair_drafts(workspace_id, ticket_id, monitor_target_id, connection_id, record_id, proposed_content, state, plan, requested_by)
             VALUES (?, ?, ?, ?, ?, '93.184.216.35', 'ready', ?::jsonb, ?) RETURNING id",
            [$v['ws'], $v['repair_ticket'], $v['target'], $v['connection'], str_repeat('cd', 16), $plan, $v['alice']->id])->id;
        $rq = $this->owner()->selectOne(
            "INSERT INTO quotes(workspace_id, ticket_id, monitor_target_id, action_code, description, amount_paise, params, issued_by, fingerprint, expires_at)
             VALUES (?, ?, ?, 'dns.record_fix', 'Victim DNS change', 0, ?::jsonb, ?, repeat('0', 64), now() + interval '1 day') RETURNING id, fingerprint",
            [$v['ws'], $v['repair_ticket'], $v['target'], $plan, $v['alice']->id]);
        $this->owner()->unprepared('ALTER TABLE approvals DISABLE TRIGGER USER');
        $v['approval'] = $this->owner()->selectOne(
            "INSERT INTO approvals(workspace_id, ticket_id, quote_id, fingerprint, approved_by, amount_paise, action_fingerprint, expires_at, payment_verified)
             VALUES (?, ?, ?, ?, ?, 10000, ?, now() + interval '1 day', true) RETURNING id",
            [$v['ws'], $v['repair_ticket'], $rq->id, $rq->fingerprint, $v['alice']->id, $rq->fingerprint])->id;
        $this->owner()->unprepared('ALTER TABLE approvals ENABLE TRIGGER USER');
        $v['attempt'] = $this->owner()->selectOne(
            "INSERT INTO repair_attempts(workspace_id, ticket_id, quote_id, approval_id, connection_id, connection_secret_version, zone_id, record_id, plan,
                                         plan_fingerprint, idempotency_key, requested_by, state, customer_message)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?::jsonb, ?, 'victim-key-001', ?, 'resolved', 'Victim work done') RETURNING id",
            [$v['ws'], $v['repair_ticket'], $rq->id, $v['approval'], $v['connection'], str_repeat('ab', 16), str_repeat('cd', 16), $plan, $rq->fingerprint, $v['alice']->id])->id;
        $this->owner()->insert("INSERT INTO repair_events(workspace_id, attempt_id, kind, detail) VALUES (?, ?, 'write', '{\"ref\": \"victim-ray\"}')", [$v['ws'], $v['attempt']]);
        $this->owner()->insert("INSERT INTO repair_controls(workspace_id, paused, reason) VALUES (?, true, 'Victim paused')", [$v['ws']]);
        // M4: an active WHM connector with a discovered and an imported asset.
        $v['connector'] = $this->owner()->selectOne(
            "INSERT INTO connectors(workspace_id, kind, label, base_host, username, secret_sealed, declared_capabilities, verified_capabilities, status, created_by, last_success_at)
             VALUES (?, 'whm_reseller', 'Victim WHM', 'panel.victim.example', 'victimr', 'v1:victim-whm-sealed', connector_declared('whm_reseller'),
                     connector_declared('whm_reseller'), 'active', ?, now()) RETURNING id", [$v['ws'], $v['alice']->id])->id;
        $v['asset'] = $this->owner()->selectOne(
            "INSERT INTO connector_assets(workspace_id, connector_id, kind, external_id, name, facts, last_seen_sync)
             VALUES (?, ?, 'domain', 'victim-hidden.example', 'victim-hidden.example', '{\"plan\": \"Victim work\"}', 1) RETURNING id", [$v['ws'], $v['connector']])->id;
        $this->owner()->insert("INSERT INTO connector_assets(workspace_id, connector_id, kind, external_id, name, facts, last_seen_sync, import_state, website_id)
             VALUES (?, ?, 'account', 'victim', 'victim-secret.example', '{\"plan\": \"Victim work\"}', 1, 'imported', ?)", [$v['ws'], $v['connector'], $v['site']]);
        // M6: merchant profile, a paid order with a receipt and a refund.
        $this->owner()->insert("INSERT INTO merchant_profiles(workspace_id, legal_name, razorpay_key_id, key_secret_enc, webhook_secret_enc, updated_by)
             VALUES (?, 'Victim Merchant LLP', 'rzp_test_VictimKey001', ?, ?, ?)",
            [$v['ws'], \Illuminate\Support\Facades\Crypt::encryptString('victim-key-secret'), \Illuminate\Support\Facades\Crypt::encryptString('victim-hook-secret'), $v['alice']->id]);
        $v['order'] = $this->owner()->selectOne(
            "INSERT INTO payment_orders(workspace_id, ticket_id, quote_id, approval_id, amount_paise, mode, merchant_key_id, merchant_name, provider_order_id,
                                        provider_payment_id, status, idempotency_key, created_by, paid_at)
             VALUES (?, ?, ?, ?, 10000, 'test', 'rzp_test_VictimKey001', 'Victim Merchant LLP', 'order_Victim000001', 'pay_Victim000001', 'paid_verified',
                     'victim-idem-0001', ?, now()) RETURNING id", [$v['ws'], $v['repair_ticket'], $rq->id, $v['approval'], $v['alice']->id])->id;
        $v['receipt'] = $this->owner()->selectOne(
            "INSERT INTO payment_receipts(workspace_id, order_id, number, seller_name, description, action_code, amount_paise, currency, tax_note, mode, provider_payment_id)
             VALUES (?, ?, 'VIC-2026-000001', 'Victim Merchant LLP', 'Victim work', 'dns.record_fix', 10000, 'INR', 'n/a', 'test', 'pay_Victim000001') RETURNING id",
            [$v['ws'], $v['order']])->id;
        $this->owner()->insert("INSERT INTO refunds(workspace_id, order_id, amount_paise, reason, requested_by) VALUES (?, ?, 100, 'Victim work refund', ?)",
            [$v['ws'], $v['order'], $v['alice']->id]);
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
            'POST api/v1/websites/{website}/recheck', 'GET api/v1/incidents/{incident}', 'POST api/v1/incidents/{incident}/acknowledge',
            'POST api/v1/notifications/{notification}/read' => [],
            'POST api/v1/websites/{website}/diagnose', 'GET api/v1/websites/{website}/diagnostics', 'GET api/v1/diagnostics/{run}' => [],
            'GET api/v1/websites/{website}/dns-connections', 'POST api/v1/dns-connections/{connection}/revoke', 'GET api/v1/tickets/{ticket}/repairs',
            'GET api/v1/repair-drafts/{draft}', 'POST api/v1/repair-drafts/{draft}/quote', 'GET api/v1/repair-attempts/{attempt}' => [],
            'POST api/v1/websites/{website}/dns-connections' => ['zone_id' => str_repeat('ef', 16), 'zone_name' => 'attacker.example', 'api_token' => 'attacker-token-00000000'],
            'POST api/v1/dns-connections/{connection}/rotate' => ['api_token' => 'attacker-token-00000000'],
            'POST api/v1/tickets/{ticket}/repair-drafts' => ['connection_id' => $v['connection'], 'monitor_target_id' => $v['target'], 'record_id' => str_repeat('cd', 16), 'proposed_content' => '93.184.216.36'],
            'POST api/v1/approvals/{approval}/repairs' => ['idempotency_key' => 'attacker-key-01'],
            'GET api/v1/connectors/{connector}', 'GET api/v1/connectors/{connector}/events', 'POST api/v1/connectors/{connector}/revoke',
            'GET api/v1/websites/{website}/providers' => [],
            'POST api/v1/connectors/{connector}/rotate' => ['api_token' => 'attacker-token-00000000'],
            'POST api/v1/connectors/{connector}/import', 'POST api/v1/connectors/{connector}/ignore' => ['asset_ids' => [$v['asset']]],
            'POST api/v1/workspaces/{workspace}/reopen' => [],
            'POST api/v1/billing/coverage/{coverage}/revoke', 'GET api/v1/tickets/{ticket}/payments', 'GET api/v1/receipts/{receipt}' => [],
            'POST api/v1/approvals/{approval}/checkout' => ['idempotency_key' => 'attacker-idem-01'],
            'POST api/v1/payments/{order}/client-confirm' => ['razorpay_payment_id' => 'pay_Victim000001', 'razorpay_order_id' => 'order_Victim000001',
                'razorpay_signature' => str_repeat('a', 64)],
            'POST api/v1/payments/{order}/refunds' => ['amount_paise' => 100, 'reason' => 'attacker refund'],
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
            'incident' => $this->v['incident'],
            'notification' => $this->v['notification'],
            'run' => $this->v['run'],
            'connection' => $this->v['connection'],
            'draft' => $this->v['draft'],
            'approval' => $this->v['approval'],
            'attempt' => $this->v['attempt'],
            'connector' => $this->v['connector'],
            'coverage' => $this->v['coverage'],
            'workspace' => $this->v['ws'],
            'order' => $this->v['order'],
            'receipt' => $this->v['receipt'],
            default => $this->fail("Unknown route parameter {{$name}}: map it to a victim id in the sweep."),
        };
    }

    /** @return array<int, array{0: string, 1: string}> [method, uri] for every v1 route with parameters */
    private function idRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (!str_starts_with($r->uri(), 'api/v1/') || !str_contains($r->uri(), '{')) continue;
            // Provider webhooks carry no session; their only authentication is the signature (PaymentsTest).
            if (str_starts_with($r->uri(), 'api/v1/webhooks/')) continue;
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
        $secrets = ['Victim Client Pvt Ltd', 'victim-secret.example', 'Victim work', 'victim-cf-token-secret', 'victim-ray', 'Victim DNS change', 'alice@test.local', 'pending@test.local',
            'Victim view', $this->v['fingerprint'], 'victim-hidden.example', 'panel.victim.example', 'victim-whm-sealed',
            'Victim Merchant', 'VIC-2026', 'order_Victim', 'pay_Victim', 'AMC-TEST-1'];

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
