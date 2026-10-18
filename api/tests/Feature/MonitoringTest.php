<?php

namespace Tests\Feature;

use App\Mail\IncidentMail;
use App\Monitoring\Dns\DnsClient;
use App\Monitoring\Http\HttpFetcher;
use App\Monitoring\ProbeHealth;
use App\Monitoring\Recorder;
use App\Monitoring\Reading;
use App\Monitoring\Tls\TlsInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\FakeDnsClient;
use Tests\Fakes\FakeFetcher;
use Tests\Fakes\FakeProbeHealth;
use Tests\Fakes\FakeTlsInspector;
use Tests\TestCase;

/** M3 end to end through the worker command, the worker DB role and the API. Probes are faked; no network. */
class MonitoringTest extends TestCase
{
    private array $f;
    private FakeDnsClient $fdns;
    private FakeFetcher $fhttp;
    private FakeTlsInspector $ftls;
    private FakeProbeHealth $health;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['upfixy.monitor.retry_delays_ms' => []]);
        $this->fdns = new FakeDnsClient();
        $this->fhttp = new FakeFetcher();
        $this->ftls = new FakeTlsInspector();
        $this->health = new FakeProbeHealth();
        $this->app->instance(DnsClient::class, $this->fdns);
        $this->app->instance(HttpFetcher::class, $this->fhttp);
        $this->app->instance(TlsInspector::class, $this->ftls);
        $this->app->instance(ProbeHealth::class, $this->health);

        $f = [];
        foreach (['olive', 'adam', 'tina', 'tom', 'carl', 'cara', 'bill', 'sid', 'una', 'bob'] as $n) $f[$n] = $this->makeUser($n, $n !== 'una');
        $f['ws'] = $this->makeWorkspace('Agency');
        $f['wsB'] = $this->makeWorkspace('Other agency');
        foreach ([['olive', 'owner'], ['adam', 'admin'], ['tina', 'technician'], ['tom', 'technician'], ['carl', 'client_owner'],
                  ['cara', 'client_owner'], ['bill', 'billing'], ['sid', 'client_staff'], ['una', 'technician']] as [$u, $r]) {
            $this->addMember($f['ws'], $f[$u], $r);
        }
        $this->addMember($f['wsB'], $f['bob'], 'owner');
        $f['mitra'] = $this->makeClient($f['ws'], 'Mitra');
        $f['other'] = $this->makeClient($f['ws'], 'Other');
        foreach (['tina', 'carl', 'sid', 'una'] as $u) $this->assign($f['ws'], $f['mitra'], $f[$u]);
        foreach (['tom', 'cara'] as $u) $this->assign($f['ws'], $f['other'], $f[$u]);
        [$f['shop'], $f['shopT']] = $this->makeSite($f['ws'], $f['mitra'], 'shop.mitra.example');
        $this->owner()->update("UPDATE monitor_targets SET state = 'pending_validation' WHERE id = ?", [$f['shopT']]);
        [$f['siteB'], $f['siteBT']] = $this->makeSite($f['wsB'], null, 'b.example');
        $this->owner()->update('UPDATE monitor_targets SET next_check_at = now() + interval \'1 hour\' WHERE id = ?', [$f['siteBT']]);
        $this->fdns->set('shop.mitra.example', ['93.184.216.34']);
        $this->fhttp->routes['https://shop.mitra.example/'] = 200;
        $this->f = $f;
    }

    private function cycle(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->owner()->update("UPDATE monitor_targets SET next_check_at = now() WHERE id = ?", [$this->f['shopT']]);
            Artisan::call('upfixy:monitor', ['--once' => true]);
        }
    }

    private function siteDown(string $how = 'HTTP_TIMEOUT'): void
    {
        $this->fhttp->routes['https://shop.mitra.example/'] = $how;
    }

    private function target(): object
    {
        return $this->owner()->selectOne('SELECT * FROM monitor_targets WHERE id = ?', [$this->f['shopT']]);
    }

    public function test_first_cycle_validates_the_target_and_stores_evidence_with_provenance(): void // AC-AST-001.1, §17.1, NFR-15
    {
        $before = now();
        $this->cycle();
        $t = $this->target();
        $this->assertSame('active', $t->state);
        $this->assertSame(1, (int) $t->last_cycle_no);
        $this->assertNull($t->lease_until);
        $next = strtotime($t->next_check_at) - $before->getTimestamp();
        $this->assertGreaterThanOrEqual(295, $next);
        $this->assertLessThanOrEqual(331, $next, 'interval + up to 10% jitter');

        $run = $this->owner()->selectOne('SELECT * FROM check_runs WHERE monitor_target_id = ?', [$this->f['shopT']]);
        $this->assertSame(['ok', 'dev-local', 'dev', 1], [$run->outcome, $run->region, $run->worker_version, (int) $run->cycle_no]);
        $this->assertNotNull($run->scheduled_at);
        $obs = $this->owner()->select('SELECT check_kind, severity, result_code, check_run_id, region, detail FROM observations WHERE monitor_target_id = ? ORDER BY check_kind', [$this->f['shopT']]);
        $kinds = array_map(fn ($o) => $o->check_kind, $obs);
        sort($kinds);
        $this->assertSame(['dns', 'http', 'tls'], $kinds);
        foreach ($obs as $o) {
            $this->assertSame($run->id, $o->check_run_id);
            $this->assertSame('dev-local', $o->region);
        }
        $http = collect($obs)->firstWhere('check_kind', 'http');
        $this->assertSame('HTTP_OK', $http->result_code);
        $this->assertSame(200, json_decode($http->detail, true)['status']);

        // The board now shows Healthy from these fresh readings.
        $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$this->f['shop']}")->assertJsonPath('lane', 'healthy');
        // History exposes the evidence ID and redacted detail.
        $h = $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$this->f['shop']}/history?kind=http")->assertOk();
        $this->assertTrue(\Illuminate\Support\Str::isUuid($h->json('data.0.id')));
        $this->assertSame('https://shop.mitra.example/', $h->json('data.0.detail.chain.0.url'));
    }

    public function test_private_address_targets_are_rejected_and_never_contacted(): void // AC-AST-001.2, §14.1
    {
        $this->fdns->set('shop.mitra.example', ['10.0.0.8']);
        $this->cycle();
        $t = $this->target();
        $this->assertSame('rejected', $t->state);
        $this->assertStringContainsString('private', $t->state_reason);
        $this->assertSame([], $this->fhttp->connections);
        // Rejected targets are not claimed again.
        $this->cycle();
        $this->assertSame(1, $this->owner()->table('check_runs')->count());
    }

    public function test_debounce_one_failure_then_success_opens_nothing_three_failures_open(): void // AC-MON-005.1
    {
        $this->cycle();                     // ok
        $this->siteDown();
        $this->cycle();                     // fail 1
        $this->fhttp->routes['https://shop.mitra.example/'] = 200;
        $this->cycle();                     // ok again
        $this->assertSame(0, $this->owner()->table('incidents')->count());
        $this->siteDown();
        $this->cycle(2);
        $this->assertSame(0, $this->owner()->table('incidents')->count(), 'two failures are not enough');
        $this->cycle();
        $i = $this->owner()->selectOne('SELECT * FROM incidents');
        $this->assertSame(['open', 'http', 'HTTP_TIMEOUT'], [$i->status, $i->root_kind, $i->result_code]);
        $runs = $this->owner()->select("SELECT finished_at FROM check_runs WHERE outcome = 'target_failure' ORDER BY cycle_no");
        $this->assertSame(strtotime($runs[count($runs) - 3]->finished_at), strtotime($i->first_failure_at));
    }

    public function test_incident_alerts_reach_exactly_the_people_who_can_see_the_site(): void // ALR-001, AC-ALR-001.1
    {
        $this->siteDown();
        $this->cycle(3);
        $incident = $this->owner()->selectOne('SELECT id FROM incidents')->id;
        $to = collect($this->owner()->select(
            "SELECT u.email FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.incident_id = ? AND n.kind = 'incident_opened' ORDER BY 1", [$incident]))->pluck('email')->all();
        // Owner, admin, Mitra's technician, Mitra's client owner and client staff. Not: Other's people, billing,
        // the unverified technician, or anyone in another workspace.
        $this->assertSame(['adam@test.local', 'carl@test.local', 'olive@test.local', 'sid@test.local', 'tina@test.local'], $to);
        Mail::assertSent(IncidentMail::class, 5);
        Mail::assertSent(IncidentMail::class, fn (IncidentMail $m) => count($m->to) === 1 && $m->cc === [] && $m->bcc === []);
        Mail::assertNotSent(IncidentMail::class, fn (IncidentMail $m) => $m->hasTo('cara@test.local') || $m->hasTo('tom@test.local') || $m->hasTo('bob@test.local'));
        $this->assertSame(5, $this->owner()->table('notifications')->whereNotNull('emailed_at')->count());

        // A further failing cycle does not alert again (deduplicated).
        $this->cycle();
        Mail::assertSent(IncidentMail::class, 5);
    }

    public function test_recovery_after_two_good_cycles_resolves_with_timestamps(): void // AC-MON-008.1
    {
        $this->siteDown();
        $this->cycle(3);
        $this->fhttp->routes['https://shop.mitra.example/'] = 200;
        $this->cycle();
        $this->assertSame('open', $this->owner()->table('incidents')->value('status'), 'one good cycle is not recovery');
        $this->cycle();
        $i = $this->owner()->selectOne('SELECT * FROM incidents');
        $this->assertSame('resolved', $i->status);
        $this->assertNotNull($i->resolved_at);
        $this->assertNotNull($i->last_failure_at);
        $this->assertSame(5, (int) $i->recovered_cycle);
        $events = $this->owner()->table('incident_events')->orderBy('id')->pluck('kind')->all();
        $this->assertSame(['opened', 'resolved'], $events);
        $this->assertSame(5, $this->owner()->table('notifications')->where('kind', 'incident_resolved')->count());
    }

    public function test_one_incident_per_target_deduplicated_by_root_event(): void // MON-008
    {
        $this->fdns->rcode('shop.mitra.example', 'NXDOMAIN');
        $this->cycle(3);
        $i = $this->owner()->selectOne('SELECT * FROM incidents');
        $this->assertSame(['dns', 'DNS_NXDOMAIN'], [$i->root_kind, $i->result_code]);
        $obs = $this->owner()->table('observations')->where('cycle_no', 3)->pluck('result_code', 'check_kind')->all();
        $this->assertSame(['dns' => 'DNS_NXDOMAIN', 'tls' => 'TLS_NOT_CHECKED', 'http' => 'HTTP_NOT_CHECKED'], $obs + []);
        // DNS fixed but the site now times out: same incident, root changes.
        $this->fdns->set('shop.mitra.example', ['93.184.216.34']);
        $this->siteDown();
        $this->cycle();
        $this->assertSame(1, $this->owner()->table('incidents')->count());
        $this->assertSame('HTTP_TIMEOUT', $this->owner()->table('incidents')->value('result_code'));
        $this->assertContains('root_changed', $this->owner()->table('incident_events')->pluck('kind')->all());
    }

    public function test_a_broken_probe_never_opens_an_incident(): void // §17.1
    {
        $this->health->ok = false;
        $this->cycle(5);
        $this->assertSame(0, $this->owner()->table('incidents')->count());
        $this->assertSame(['probe_error'], $this->owner()->table('check_runs')->distinct()->pluck('outcome')->all());
        $this->assertSame([], $this->fhttp->connections);
        // Health becomes Unknown, not Critical.
        $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$this->f['shop']}")->assertJsonPath('lane', 'unknown');
    }

    public function test_recording_the_same_cycle_twice_changes_nothing(): void // idempotency, AC-MON-005.3
    {
        $db = DB::connection('pgsql_worker');
        $t = $db->selectOne('SELECT * FROM worker_claim_due(10, 10, 60)');
        $rec = app(Recorder::class);
        $readings = [Reading::fail('dns', 'DNS_NXDOMAIN'), Reading::unknown('http', 'inconclusive', 'HTTP_NOT_CHECKED')];
        $rec->record($t, $readings, 'pending_validation', null, now(), now());
        $rec->record($t, $readings, 'pending_validation', null, now(), now());
        $this->assertSame(1, $this->owner()->table('check_runs')->count());
        $this->assertSame(2, $this->owner()->table('observations')->count());
    }

    public function test_crashed_cycle_is_reclaimed_with_the_same_number_then_dead_lettered(): void // NFR-16
    {
        $db = DB::connection('pgsql_worker');
        $first = $db->selectOne('SELECT * FROM worker_claim_due(10, 10, 60)');
        $this->assertNull($db->selectOne('SELECT * FROM worker_claim_due(10, 10, 60)'), 'leased targets are not handed out twice');
        foreach ([2, 3, 4] as $n) {
            $this->owner()->update("UPDATE monitor_targets SET lease_until = now() - interval '1 second' WHERE id = ?", [$this->f['shopT']]);
            $again = $db->selectOne('SELECT * FROM worker_claim_due(10, 10, 60)');
            $this->assertSame([(int) $first->cycle_no, $n], [(int) $again->cycle_no, (int) $again->claims]);
            $this->assertSame($first->scheduled_at, $again->scheduled_at);
        }
        // claims (4) > max_claims (3): the command records a probe error instead of retrying forever.
        $this->owner()->update("UPDATE monitor_targets SET lease_until = now() - interval '1 second' WHERE id = ?", [$this->f['shopT']]);
        Artisan::call('upfixy:monitor', ['--once' => true]);
        $run = $this->owner()->selectOne('SELECT * FROM check_runs');
        $this->assertSame(['probe_error', 1], [$run->outcome, (int) $run->cycle_no]);
        $this->assertSame([], $this->fhttp->connections);
    }

    public function test_one_large_tenant_cannot_starve_another(): void // MON-006
    {
        foreach (range(1, 8) as $i) $this->makeSite($this->f['ws'], null, "s{$i}.example");
        $this->owner()->update('UPDATE monitor_targets SET next_check_at = now() - interval \'1 hour\' WHERE workspace_id = ?', [$this->f['ws']]);
        $this->owner()->update('UPDATE monitor_targets SET next_check_at = now() WHERE id = ?', [$this->f['siteBT']]);
        $claimed = collect(DB::connection('pgsql_worker')->select('SELECT * FROM worker_claim_due(4, 2, 60)'));
        $this->assertCount(3, $claimed);
        $this->assertSame(2, $claimed->where('workspace_id', $this->f['ws'])->count());
        $this->assertSame(1, $claimed->where('workspace_id', $this->f['wsB'])->count());
    }

    public function test_roles_cannot_bypass_the_worker_functions(): void
    {
        $this->cycle();
        $app = DB::connection('pgsql');
        $ws = $this->f['ws'];
        $tries = [
            fn () => $app->select('SELECT * FROM worker_claim_due(1, 1, 60)'),
            fn () => $app->insert("INSERT INTO check_runs(workspace_id, monitor_target_id, cycle_no, scheduled_at, started_at, finished_at, worker, worker_version, region, outcome, attempts)
                                   VALUES (?, ?, 99, now(), now(), now(), 'x', 'x', 'x', 'ok', 1)", [$ws, $this->f['shopT']]),
            fn () => $app->insert("INSERT INTO incidents(workspace_id, website_id, monitor_target_id, root_kind, result_code, opened_at, first_failure_at, last_failure_at)
                                   VALUES (?, ?, ?, 'http', 'X', now(), now(), now())", [$ws, $this->f['shop'], $this->f['shopT']]),
            fn () => $app->select("SELECT * FROM worker_notify_incident(gen_random_uuid(), 'incident_opened')"),
        ];
        foreach ($tries as $i => $try) {
            $app->beginTransaction();
            $app->select("SELECT set_config('app.user_id', ?, true), set_config('app.workspace_id', ?, true)", [$this->f['olive']->id, $ws]);
            try {
                $try();
                $this->fail("app role was allowed (#{$i})");
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('denied', $e->getMessage());
            } finally {
                $app->rollBack();
            }
        }
        $w = DB::connection('pgsql_worker');
        $w->beginTransaction();
        $w->select("SELECT set_config('app.workspace_id', ?, true)", [$ws]);
        try {
            $w->update("UPDATE check_runs SET outcome = 'ok'");
            $this->fail('worker edited a check run');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('denied', $e->getMessage());
        } finally {
            $w->rollBack();
        }
    }

    // ------------------------------------------------------------------ API
    private function openIncident(): string
    {
        $this->siteDown();
        $this->cycle(3);
        return $this->owner()->selectOne('SELECT id FROM incidents')->id;
    }

    public function test_incident_visibility_and_acknowledgement_permissions(): void
    {
        $id = $this->openIncident();
        $f = $this->f;
        $this->api($f['tina'], $f['ws'], 'GET', 'incidents')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Website not responding');
        $this->api($f['tom'], $f['ws'], 'GET', 'incidents')->assertJsonCount(0, 'data');
        $this->api($f['tom'], $f['ws'], 'GET', "incidents/{$id}")->assertNotFound();
        $this->api($f['cara'], $f['ws'], 'POST', "incidents/{$id}/acknowledge")->assertNotFound();
        $this->api($f['sid'], $f['ws'], 'POST', "incidents/{$id}/acknowledge")->assertForbidden();
        $this->api($f['bob'], $f['wsB'], 'GET', "incidents/{$id}")->assertNotFound();
        $at = $this->api($f['carl'], $f['ws'], 'POST', "incidents/{$id}/acknowledge")->assertOk()->json('acknowledged_at');
        $this->assertSame($at, $this->api($f['tina'], $f['ws'], 'POST', "incidents/{$id}/acknowledge")->assertOk()->json('acknowledged_at'), 'idempotent');
        $this->api($f['olive'], $f['ws'], 'GET', "incidents/{$id}")->assertJsonPath('events.1.kind', 'acknowledged');
        $this->assertCount(1, $this->auditRows('incident.acknowledged'));
        $this->assertCount(1, $this->auditRows('incident.acknowledge'));
    }

    public function test_check_now_is_rate_limited_and_honest_about_stored_results(): void // KB-003, AC-KB-003.1
    {
        $f = $this->f;
        $this->cycle();
        $this->api($f['sid'], $f['ws'], 'POST', "websites/{$f['shop']}/recheck")->assertStatus(202)->assertJsonPath('queued', 1);
        $this->assertLessThanOrEqual(time(), strtotime($this->target()->next_check_at));
        $this->api($f['carl'], $f['ws'], 'POST', "websites/{$f['shop']}/recheck")->assertStatus(202)->assertJsonPath('queued', 0)
            ->assertJsonPath('message', 'A check was requested in the last 2 minutes. Showing the latest stored results.');
        $this->api($f['bill'], $f['ws'], 'POST', "websites/{$f['shop']}/recheck")->assertNotFound();
        $this->api($f['tom'], $f['ws'], 'POST', "websites/{$f['shop']}/recheck")->assertNotFound();
        $this->api($f['bob'], $f['wsB'], 'POST', "websites/{$f['shop']}/recheck")->assertNotFound();
    }

    public function test_inbox_and_preferences(): void // ALR-001
    {
        $f = $this->f;
        // Carl turns email off for Mitra only; in-app stays on.
        $this->api($f['carl'], $f['ws'], 'PUT', 'notification-preferences', ['client_id' => $f['mitra'], 'email' => false, 'in_app' => true])->assertOk();
        // Tina turns everything off workspace-wide.
        $this->api($f['tina'], $f['ws'], 'PUT', 'notification-preferences', ['email' => false, 'in_app' => false])->assertOk()
            ->assertJsonPath('workspace.email', false);
        // A client the user cannot see cannot be configured.
        $this->api($f['carl'], $f['ws'], 'PUT', 'notification-preferences', ['client_id' => $f['other'], 'email' => false, 'in_app' => false])->assertNotFound();
        $this->openIncident();
        Mail::assertNotSent(IncidentMail::class, fn ($m) => $m->hasTo('carl@test.local') || $m->hasTo('tina@test.local'));
        Mail::assertSent(IncidentMail::class, 3);
        $inbox = $this->api($f['carl'], $f['ws'], 'GET', 'notifications')->assertOk()->assertJsonPath('unread', 1);
        $this->api($f['tina'], $f['ws'], 'GET', 'notifications')->assertJsonPath('unread', 0);
        $nid = $inbox->json('data.0.id');
        $this->api($f['olive'], $f['ws'], 'POST', "notifications/{$nid}/read")->assertNotFound(); // someone else's
        $this->api($f['carl'], $f['ws'], 'POST', "notifications/{$nid}/read")->assertNoContent();
        $this->api($f['carl'], $f['ws'], 'GET', 'notifications')->assertJsonPath('unread', 0);
        $this->api($f['olive'], $f['ws'], 'POST', 'notifications/read-all')->assertNoContent();
        $this->api($f['olive'], $f['ws'], 'GET', 'notifications')->assertJsonPath('unread', 0);
    }

    public function test_a_workspace_with_acknowledged_incidents_can_be_removed(): void // NFR-14
    {
        $id = $this->openIncident();
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "incidents/{$id}/acknowledge")->assertOk();
        $this->owner()->delete('DELETE FROM workspaces WHERE id = ?', [$this->f['ws']]);
        $this->assertSame(0, $this->owner()->table('incidents')->count());
        $this->assertSame(1, $this->owner()->table('workspaces')->count());
    }
}
