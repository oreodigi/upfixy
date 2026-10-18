<?php

namespace Tests\Feature;

use App\Monitoring\Dns\DnsClient;
use App\Monitoring\Http\HttpFetcher;
use App\Repairs\DnsProvider;
use App\Repairs\Executor;
use App\Repairs\SecretBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeDnsClient;
use Tests\Fakes\FakeDnsProvider;
use Tests\Fakes\FakeFetcher;
use Tests\TestCase;

/**
 * M7 FIX-003/004/005 end to end against a synthetic zone (no network, no real provider):
 * API → executor (upfixy_executor role) → fake Cloudflare → verification → ticket.
 */
class RepairsTest extends TestCase
{
    private const ZONE = '0123456789abcdef0123456789abcdef';
    private const REC = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const TOKEN = 'cf-scoped-test-token-0001';
    private const OLD = '93.184.216.34';
    private const NEW = '93.184.216.35';

    private array $f;
    private FakeDnsProvider $cf;
    private FakeDnsClient $resolvers;
    private FakeFetcher $web;

    protected function setUp(): void
    {
        parent::setUp();
        $k = SecretBox::keygen();
        config(['upfixy.repairs.enabled' => true, 'upfixy.repairs.public_key' => $k['public'], 'upfixy.repairs.keypair' => $k['keypair']]);
        // The test harness empties every table; re-create the global control row as RELEASED (tests only).
        $this->owner()->insert("INSERT INTO repair_controls(workspace_id, paused, reason) VALUES (NULL, false, 'released for tests')");

        $this->cf = new FakeDnsProvider();
        $this->cf->zones[self::ZONE] = ['name' => 'mitra.example', 'token' => self::TOKEN];
        $this->cf->records[self::REC] = ['zone' => self::ZONE, 'type' => 'A', 'name' => 'shop.mitra.example', 'content' => self::OLD, 'ttl' => 300, 'proxied' => false];
        $this->cf->records['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] = ['zone' => self::ZONE, 'type' => 'MX', 'name' => 'shop.mitra.example', 'content' => 'mail.mitra.example', 'ttl' => 300, 'proxied' => false];
        $this->cf->records['cccccccccccccccccccccccccccccccc'] = ['zone' => self::ZONE, 'type' => 'A', 'name' => '*.mitra.example', 'content' => self::OLD, 'ttl' => 300, 'proxied' => false];
        $this->cf->records['dddddddddddddddddddddddddddddddd'] = ['zone' => self::ZONE, 'type' => 'A', 'name' => 'admin.mitra.example', 'content' => self::OLD, 'ttl' => 300, 'proxied' => false];
        $this->resolvers = new FakeDnsClient();
        $this->resolvers->set('shop.mitra.example', [self::OLD]);
        $this->web = new FakeFetcher();
        $this->web->routes['https://shop.mitra.example/'] = 200;
        $this->app->instance(DnsProvider::class, $this->cf);
        $this->app->instance(DnsClient::class, $this->resolvers);
        $this->app->instance(HttpFetcher::class, $this->web);
        config(['upfixy.monitor.resolvers' => ['1.1.1.1', '8.8.8.8']]);

        $f = [];
        foreach (['olive', 'adam', 'tina', 'tom', 'carl', 'bob'] as $n) $f[$n] = $this->makeUser($n);
        $f['ws'] = $this->makeWorkspace('Agency');
        $f['wsB'] = $this->makeWorkspace('Other');
        foreach ([['olive', 'owner'], ['adam', 'admin'], ['tina', 'technician'], ['tom', 'technician'], ['carl', 'client_owner']] as [$u, $r]) $this->addMember($f['ws'], $f[$u], $r);
        $this->addMember($f['wsB'], $f['bob'], 'owner');
        $f['mitra'] = $this->makeClient($f['ws'], 'Mitra');
        $f['other'] = $this->makeClient($f['ws'], 'Other');
        $this->assign($f['ws'], $f['mitra'], $f['tina']);
        $this->assign($f['ws'], $f['mitra'], $f['carl']);
        $this->assign($f['ws'], $f['other'], $f['tom']);
        [$f['site'], $f['target']] = $this->makeSite($f['ws'], $f['mitra'], 'shop.mitra.example');
        foreach (['olive', 'adam', 'tina', 'tom', 'carl', 'bob'] as $u) $this->enableMfa($f[$u]);
        $this->verifyOwnership($f['olive'], $f['ws'], $f['site'], 'shop.mitra.example');
        $this->observe($f['ws'], $f['target'], 'http', 'ok', 2, 1);
        $f['ticket'] = $this->makeTicket($f['ws'], $f['site'], 'investigating');
        $this->f = $f;
    }

    // ------------------------------------------------------------- helpers
    private function connect(): string
    {
        return $this->api($this->f['olive'], $this->f['ws'], 'POST', "websites/{$this->f['site']}/dns-connections",
            ['zone_id' => self::ZONE, 'zone_name' => 'mitra.example', 'api_token' => self::TOKEN], mfaFresh: true)->assertCreated()->json('id');
    }

    private function draft(string $conn, string $record = self::REC, string $value = self::NEW): string
    {
        return $this->api($this->f['tina'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/repair-drafts",
            ['connection_id' => $conn, 'monitor_target_id' => $this->f['target'], 'record_id' => $record, 'proposed_content' => $value], mfaFresh: true)
            ->assertCreated()->json('id');
    }

    private function runExecutor(int $max = 12): void
    {
        $ex = app(Executor::class);
        for ($i = 0; $i < $max; $i++) {
            $this->owner()->update("UPDATE repair_attempts SET next_verify_at = now() WHERE state = 'verifying'");
            if (!$ex->runOnce()) {
                if (!$this->owner()->selectOne("SELECT 1 AS x FROM repair_attempts WHERE state = 'verifying'")) return;
            }
        }
    }

    /** Draft → executor preview → quote → payer approval (MFA) → verified payment → ready for work. */
    private function approved(?string $conn = null, bool $paid = true): array
    {
        $conn ??= $this->connect();
        $d = $this->draft($conn);
        $this->runExecutor();
        $dr = $this->api($this->f['tina'], $this->f['ws'], 'GET', "repair-drafts/{$d}");
        $this->assertSame('ready', $dr->json('state'), (string) $dr->json('refusal'));
        $q = $this->api($this->f['tina'], $this->f['ws'], 'POST', "repair-drafts/{$d}/quote")->assertCreated()->json('quote_id');
        $quote = $this->owner()->selectOne('SELECT fingerprint, amount_paise FROM quotes WHERE id = ?', [$q]);
        $ap = $this->api($this->f['carl'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/approvals",
            ['quote_id' => $q, 'fingerprint' => $quote->fingerprint], mfaFresh: true)->assertCreated()->json('id');
        if ($paid) {
            // Stands in for the verified payment webhook (M6), the only writer of payment_verified.
            $this->owner()->update('UPDATE approvals SET payment_verified = true WHERE id = ?', [$ap]);
            $v = $this->owner()->selectOne('SELECT version FROM tickets WHERE id = ?', [$this->f['ticket']])->version;
            $this->api($this->f['tina'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/transition", ['to' => 'ready_for_work', 'version' => $v])->assertOk();
        }
        return ['conn' => $conn, 'draft' => $d, 'quote' => $q, 'approval' => $ap, 'amount' => (int) $quote->amount_paise];
    }

    private function request(string $approval, ?string $key = null)
    {
        return $this->api($this->f['tina'], $this->f['ws'], 'POST', "approvals/{$approval}/repairs", ['idempotency_key' => $key ?? Str::random(16)], mfaFresh: true);
    }

    private function attempt(?string $id = null): object
    {
        return $id ? $this->owner()->selectOne('SELECT * FROM repair_attempts WHERE id = ?', [$id])
            : $this->owner()->selectOne('SELECT * FROM repair_attempts ORDER BY created_at DESC LIMIT 1');
    }

    private function events(string $id): array
    {
        return $this->owner()->table('repair_events')->where('attempt_id', $id)->orderBy('id')->pluck('kind')->all();
    }

    private function ticketState(): string
    {
        return $this->owner()->table('tickets')->where('id', $this->f['ticket'])->value('state');
    }

    private function propagate(): void
    {
        $this->cf->afterWrite = fn () => $this->resolvers->set('shop.mitra.example', [self::NEW]);
    }

    // ------------------------------------------------------------ happy path
    public function test_one_approved_change_is_applied_once_verified_and_resolved(): void // AC-FIX-003.1
    {
        $a = $this->approved();
        $this->assertSame(10000, $a['amount'], '₹100 from the catalogue, not from the request');
        $this->propagate();
        $id = $this->request($a['approval'])->assertStatus(202)->json('id');
        $this->runExecutor();
        $at = $this->attempt($id);
        $this->assertSame('resolved', $at->state);
        $this->assertSame(self::NEW, $this->cf->records[self::REC]['content']);
        $this->assertCount(1, $this->cf->writes(), 'exactly one provider write');
        $this->assertSame(300, $this->cf->records[self::REC]['ttl']);
        $this->assertFalse($this->cf->records[self::REC]['proxied']);
        $this->assertSame('resolved', $this->ticketState());
        $ev = $this->events($id);
        foreach (['preflight_started', 'before_image', 'write_intent', 'write', 'verifying_started', 'verify_dns', 'verify_http', 'finished_resolved'] as $k) {
            $this->assertContains($k, $ev);
        }
        $before = json_decode($this->owner()->table('repair_events')->where('attempt_id', $id)->where('kind', 'before_image')->value('detail'), true);
        $this->assertSame(self::OLD, $before['record']['content']);
        $this->assertStringStartsWith('cf-ray-test-', $before['ref']);
        // The customer sees the plain outcome; staff see every step.
        $this->api($this->f['carl'], $this->f['ws'], 'GET', "repair-attempts/{$id}")->assertOk()
            ->assertJsonPath('customer_message', 'The DNS record was updated and the website check now passes.')
            ->assertJsonMissingPath('events.0.detail');
        $this->api($this->f['tina'], $this->f['ws'], 'GET', "repair-attempts/{$id}")->assertJsonPath('events.1.kind', 'before_image');
    }

    public function test_credentials_are_sealed_and_never_readable_by_the_app(): void // NFR-13
    {
        $conn = $this->connect();
        $sealed = $this->owner()->table('dns_connections')->where('id', $conn)->value('secret_sealed');
        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertStringNotContainsString(self::TOKEN, $sealed);
        $list = $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$this->f['site']}/dns-connections")->assertOk();
        $this->assertStringNotContainsString(self::TOKEN, $list->getContent());
        $this->assertStringNotContainsString($sealed, $list->getContent());
        $this->assertCount(0, $this->auditRows('dns_connection.created') === [] ? [1] : array_filter($this->auditRows('dns_connection.created'), fn ($r) => str_contains($r->detail, self::TOKEN)));
        // The app role cannot read the sealed column or call the executor's opener.
        $app = DB::connection('pgsql');
        foreach (['SELECT secret_sealed FROM dns_connections', "SELECT executor_sealed_secret('{$conn}', 1)", 'SELECT * FROM executor_claim(60)'] as $sql) {
            $app->beginTransaction();
            $app->select("SELECT set_config('app.user_id', ?, true), set_config('app.workspace_id', ?, true)", [$this->f['olive']->id, $this->f['ws']]);
            try { $app->select($sql); $this->fail("app could run: {$sql}"); }
            catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('denied', $e->getMessage()); }
            finally { $app->rollBack(); }
        }
        // Connecting needs owner/admin with fresh MFA.
        $this->api($this->f['tina'], $this->f['ws'], 'POST', "websites/{$this->f['site']}/dns-connections",
            ['zone_id' => self::ZONE, 'zone_name' => 'x.example', 'api_token' => self::TOKEN], mfaFresh: true)->assertForbidden();
        $this->api($this->f['adam'], $this->f['ws'], 'POST', "websites/{$this->f['site']}/dns-connections",
            ['zone_id' => self::ZONE, 'zone_name' => 'mitra.example', 'api_token' => self::TOKEN])->assertForbidden(); // no fresh MFA
    }

    // ------------------------------------------------------ scope (FIX-003)
    public function test_only_allowlisted_records_inside_the_website_can_be_previewed(): void // AC-FIX-003.3, AC-FIX-003.5
    {
        $conn = $this->connect();
        $cases = [
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => 'only A and AAAA records',
            'cccccccccccccccccccccccccccccccc' => 'wildcard records are never changed',
            'dddddddddddddddddddddddddddddddd' => 'not one of this website',
            'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee' => 'would not show this record',
        ];
        foreach ($cases as $rec => $why) {
            $d = $this->draft($conn, $rec);
            $this->runExecutor();
            $r = $this->api($this->f['tina'], $this->f['ws'], 'GET', "repair-drafts/{$d}")->assertJsonPath('state', 'refused');
            $this->assertStringContainsString($why, $r->json('refusal'), $rec);
        }
        foreach (['10.0.0.5' => 'private or reserved', '::1' => 'IPv4', 'not-an-ip' => 'valid IPv4', self::OLD => 'already has this value'] as $value => $why) {
            $d = $this->draft($conn, self::REC, $value);
            $this->runExecutor();
            $this->assertStringContainsString($why, $this->api($this->f['tina'], $this->f['ws'], 'GET', "repair-drafts/{$d}")->json('refusal'));
        }
        $this->assertSame([], $this->cf->writes());
    }

    public function test_a_token_for_a_different_zone_is_refused_without_leaking_it(): void // AC-FIX-003.3
    {
        $this->cf->zones[self::ZONE]['name'] = 'someone-else.example';
        $d = $this->draft($this->connect());
        $this->runExecutor();
        $r = $this->api($this->f['tina'], $this->f['ws'], 'GET', "repair-drafts/{$d}")->assertJsonPath('state', 'refused');
        $this->assertStringNotContainsString('someone-else', $r->getContent());
    }

    public function test_hand_written_automated_quotes_are_impossible(): void // S-06, AC-FIX-003.5
    {
        $conn = $this->connect();
        $forged = ['version' => 'repair-plan-v1', 'operation' => 'cloudflare.dns.update_address_record', 'connection_id' => $conn,
            'zone_id' => self::ZONE, 'record_id' => self::REC, 'record_name' => 'shop.mitra.example', 'record_type' => 'A',
            'before' => ['content' => self::OLD, 'ttl' => 300, 'proxied' => false], 'after' => ['content' => '192.0.2.66', 'ttl' => 300, 'proxied' => false]];
        foreach (['dns.record_fix', 'provider.covered_fix'] as $code) {
            $this->api($this->f['tina'], $this->f['ws'], 'POST', "tickets/{$this->f['ticket']}/quotes",
                ['monitor_target_id' => $this->f['target'], 'action_code' => $code, 'description' => 'Quick DNS fix', 'params' => $forged])->assertStatus(422);
        }
        $this->assertSame(0, $this->owner()->table('quotes')->count());
    }

    // ---------------------------------------------------- approval (FIX-003)
    public function test_execution_needs_a_live_single_use_paid_approval(): void // AC-FIX-003.4
    {
        $a = $this->approved(paid: false);
        // Not paid yet: the ticket cannot even be made ready, and a request is refused by the executor.
        $id = $this->request($a['approval'])->assertStatus(202)->json('id');
        $this->runExecutor();
        $this->assertSame('refused', $this->attempt($id)->state);
        $this->assertSame('payment is not verified', $this->attempt($id)->state_reason);
        $this->assertSame([], $this->cf->writes());
        // A used approval cannot be used again.
        $this->request($a['approval'])->assertStatus(409);
        // Unknown approvals, other clients' staff and client owners get nothing.
        $this->request((string) Str::uuid())->assertNotFound();
        $this->api($this->f['tom'], $this->f['ws'], 'POST', "approvals/{$a['approval']}/repairs", ['idempotency_key' => 'tom-key-0001'], mfaFresh: true)->assertNotFound();
        $this->api($this->f['carl'], $this->f['ws'], 'POST', "approvals/{$a['approval']}/repairs", ['idempotency_key' => 'carl-key-001'], mfaFresh: true)->assertNotFound();
        $this->api($this->f['bob'], $this->f['wsB'], 'POST', "approvals/{$a['approval']}/repairs", ['idempotency_key' => 'bob-key-0001'], mfaFresh: true)->assertNotFound();
    }

    public function test_expired_approval_is_refused(): void // AC-FIX-003.4
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->owner()->update("UPDATE approvals SET approved_at = now() - interval '2 days', expires_at = now() - interval '1 second' WHERE id = ?", [$a['approval']]);
        $this->runExecutor();
        $this->assertSame(['refused', 'the approval expired'], [$this->attempt($id)->state, $this->attempt($id)->state_reason]);
        $this->assertSame([], $this->cf->writes());
    }

    public function test_duplicate_requests_are_idempotent(): void
    {
        $a = $this->approved();
        $one = $this->request($a['approval'], 'same-key-123')->json('id');
        $two = $this->request($a['approval'], 'same-key-123')->json('id');
        $this->assertSame($one, $two);
        $this->assertSame(1, $this->owner()->table('repair_attempts')->count());
    }

    public function test_record_changed_after_approval_is_refused_without_writing(): void // AC-FIX-003.2
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->cf->records[self::REC]['ttl'] = 120; // someone edits the record after approval
        $this->runExecutor();
        $this->assertSame(['refused', 'the DNS record changed since it was approved'], [$this->attempt($id)->state, $this->attempt($id)->state_reason]);
        $this->assertSame([], $this->cf->writes());
        $this->assertSame('investigating', $this->ticketState());
    }

    public function test_changing_the_quote_voids_consent(): void // AC-FIX-003.2, AC-SVC-003.1
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->owner()->update("UPDATE quotes SET voided_at = now(), void_reason = 'price changed' WHERE id = ?", [$a['quote']]);
        $this->runExecutor();
        $this->assertSame('refused', $this->attempt($id)->state);
        $this->assertSame([], $this->cf->writes());
    }

    // ------------------------------------------------ rollback (FIX-004)
    public function test_provider_error_after_mutation_rolls_back_to_the_exact_before_image(): void // AC-FIX-004.1
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->cf->nextWrite = 'unknown_applied';
        $this->runExecutor();
        $this->assertSame('rolled_back', $this->attempt($id)->state);
        $this->assertSame(self::OLD, $this->cf->records[self::REC]['content']);
        $this->assertSame([self::NEW, self::OLD], array_column($this->cf->writes(), 2), 'both requests, in order');
        $ev = $this->events($id);
        $this->assertContains('write_unknown_outcome', $ev);
        $this->assertContains('rollback_write', $ev);
        $this->assertSame('investigating', $this->ticketState());
    }

    public function test_concurrent_edit_is_never_overwritten(): void // AC-FIX-004.2
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        // Our write lands, then someone else changes the record while we verify.
        $this->cf->afterWrite = function (FakeDnsProvider $p) { $p->records[self::REC]['content'] = '192.0.2.77'; };
        $this->runExecutor();
        $this->assertSame('needs_technician', $this->attempt($id)->state);
        $this->assertSame('192.0.2.77', $this->cf->records[self::REC]['content'], 'their change stands');
        $this->assertCount(1, $this->cf->writes());
    }

    public function test_rollback_does_not_overwrite_a_record_someone_else_changed(): void // AC-FIX-004.2
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->cf->nextWrite = 'unknown_applied';
        // Between our uncertain write and the rollback, someone edits the record.
        $real = $this->cf;
        $this->app->instance(DnsProvider::class, new class($real) implements DnsProvider {
            private int $reads = 0;
            public function __construct(private FakeDnsProvider $p) {}
            public function zone(string $t, string $z): array { return $this->p->zone($t, $z); }
            public function updateAddressRecord(string $t, string $z, string $r, array $rec): array { return $this->p->updateAddressRecord($t, $z, $r, $rec); }
            public function getRecord(string $t, string $z, string $r): array {
                if (++$this->reads === 3) $this->p->records[$r]['content'] = '192.0.2.88';
                return $this->p->getRecord($t, $z, $r);
            }
        });
        $this->runExecutor();
        $this->assertSame('needs_technician', $this->attempt($id)->state);
        $this->assertSame('192.0.2.88', $this->cf->records[self::REC]['content']);
        $this->assertContains('rollback_skipped', $this->events($id));
    }

    public function test_timeout_with_unknown_outcome_is_reconciled_before_one_retry(): void // AC-FIX-004.3
    {
        $a = $this->approved();
        $this->propagate();
        $id = $this->request($a['approval'])->json('id');
        $this->cf->nextWrite = 'unknown_not_applied';
        $this->runExecutor();
        $this->assertSame('resolved', $this->attempt($id)->state);
        $ev = $this->events($id);
        $this->assertSame(array_search('reconcile', $ev) < array_search('retry_after_reconcile', $ev), true, 'read before retry');
        $this->assertCount(2, $this->cf->writes(), 'the failed call and exactly one retry');
    }

    public function test_crash_after_sending_a_write_never_causes_a_blind_second_write(): void // AC-FIX-004.3
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        // Simulate: preflight done, write sent and applied, process died before recording it.
        $ex = app(Executor::class);
        $this->cf->afterWrite = function () { throw new \RuntimeException('process killed'); };
        try { $ex->runOnce(); } catch (\Throwable) {}
        $this->owner()->update("UPDATE repair_attempts SET state = 'executing', lease_until = NULL WHERE id = ?", [$id]); // as it was when the process died
        $this->propagate();
        $this->resolvers->set('shop.mitra.example', [self::NEW]);
        $this->runExecutor();
        $this->assertCount(1, array_filter($this->cf->writes(), fn ($w) => $w[2] === self::NEW), 'the new value was written once');
        $this->assertContains('reconcile', $this->events($id));
    }

    public function test_successful_api_call_but_failing_website_stays_unresolved(): void // AC-FIX-004.4, AC-FIX-002.1
    {
        $this->observe($this->f['ws'], $this->f['target'], 'http', 'critical', 1, 2); // it was already failing
        $a = $this->approved();
        $this->propagate();
        $this->web->routes['https://shop.mitra.example/'] = 503;
        $id = $this->request($a['approval'])->json('id');
        $this->runExecutor();
        $at = $this->attempt($id);
        $this->assertSame('needs_technician', $at->state);
        $this->assertStringContainsString('still has a problem', $at->customer_message);
        $this->assertSame('investigating', $this->ticketState());
        $this->assertSame(self::NEW, $this->cf->records[self::REC]['content'], 'not attributable to the change: no rollback');
    }

    public function test_change_that_breaks_a_working_site_is_rolled_back(): void
    {
        $a = $this->approved(); // site was OK before
        $this->propagate();
        $this->web->routes['https://shop.mitra.example/'] = 'HTTP_CONNECTION_REFUSED';
        $id = $this->request($a['approval'])->json('id');
        $this->runExecutor();
        $this->assertSame('rolled_back', $this->attempt($id)->state);
        $this->assertSame(self::OLD, $this->cf->records[self::REC]['content']);
    }

    public function test_change_not_visible_in_public_dns_is_indeterminate_not_success(): void // §25.4 step 7
    {
        config(['upfixy.repairs.verify_window_seconds' => 0]);
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id'); // resolvers keep answering the old address
        $this->runExecutor();
        $this->assertSame('needs_technician', $this->attempt($id)->state);
        $this->assertStringContainsString('not yet visible', $this->attempt($id)->customer_message);
        $this->assertNotSame('resolved', $this->ticketState());
    }

    // ------------------------------------------------- safety (FIX-005)
    public function test_kill_switches_stop_writes_even_after_approval(): void // AC-FIX-005.1
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        // Workspace switch: an admin may pause at once.
        $this->api($this->f['adam'], $this->f['ws'], 'POST', 'repair-controls', ['paused' => true, 'reason' => 'incident drill'])->assertOk();
        $this->runExecutor();
        $this->assertSame('queued', $this->attempt($id)->state);
        $this->assertContains('held', $this->events($id));
        $this->assertSame([], $this->cf->writes());
        // Resuming needs the owner with fresh MFA.
        $this->api($this->f['adam'], $this->f['ws'], 'POST', 'repair-controls', ['paused' => false, 'reason' => 'done'], mfaFresh: true)->assertForbidden();
        $this->api($this->f['olive'], $this->f['ws'], 'POST', 'repair-controls', ['paused' => false, 'reason' => 'done'])->assertForbidden();
        $this->api($this->f['olive'], $this->f['ws'], 'POST', 'repair-controls', ['paused' => false, 'reason' => 'drill over'], mfaFresh: true)->assertOk();
        // Global switch and platform flag also hold.
        $this->owner()->update("UPDATE repair_controls SET paused = true WHERE workspace_id IS NULL");
        $this->owner()->update('UPDATE repair_attempts SET lease_until = NULL');
        $this->runExecutor();
        $this->assertSame([], $this->cf->writes());
        $this->owner()->update("UPDATE repair_controls SET paused = false WHERE workspace_id IS NULL");
        config(['upfixy.repairs.enabled' => false]);
        $this->owner()->update('UPDATE repair_attempts SET lease_until = NULL');
        $this->runExecutor();
        $this->assertSame([], $this->cf->writes());
        // Released: the held attempt proceeds.
        config(['upfixy.repairs.enabled' => true]);
        $this->owner()->update('UPDATE repair_attempts SET lease_until = NULL');
        $this->propagate();
        $this->runExecutor();
        $this->assertSame('resolved', $this->attempt($id)->state);
        // Public monitoring is unaffected by repair switches (separate role and tables).
        $this->assertTrue((bool) DB::connection('pgsql_worker')->selectOne('SELECT count(*) >= 0 AS ok FROM worker_claim_due(1, 1, 60)')->ok);
    }

    public function test_global_switch_starts_paused_and_fails_closed(): void // release gate
    {
        $fresh = file_get_contents(base_path('../db/migrations/0013_repairs.sql'));
        $this->assertStringContainsString("VALUES (NULL, true, 'Repairs start paused", $fresh, 'a fresh database starts paused');
        $this->assertFalse((bool) env('UPFIXY_REPAIRS_ENABLED', false), 'platform flag defaults to off');
        // No global row at all: treated as paused, never as allowed.
        $a = $this->approved();
        $this->owner()->delete('DELETE FROM repair_controls');
        $id = $this->request($a['approval'])->json('id');
        $this->runExecutor();
        $this->assertSame('queued', $this->attempt($id)->state);
        $this->assertSame([], $this->cf->writes());
    }

    public function test_revoked_or_rotated_credentials_stop_queued_work(): void // AC-FIX-005.2
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "dns-connections/{$a['conn']}/rotate", ['api_token' => 'cf-rotated-token-0000002'], mfaFresh: true)->assertOk();
        $this->runExecutor();
        $this->assertSame(['refused', 'the DNS credential changed after this fix was requested'], [$this->attempt($id)->state, $this->attempt($id)->state_reason]);
        $this->assertSame([], $this->cf->writes());
    }

    public function test_expired_ownership_proof_stops_queued_work(): void // AC-FIX-005.2, AC-AST-002.7
    {
        $a = $this->approved();
        $id = $this->request($a['approval'])->json('id');
        $this->owner()->update("UPDATE ownership_claims SET expires_at = now() - interval '1 second'");
        $this->runExecutor();
        $this->assertSame(['refused', 'ownership or delegation for this address is no longer valid'], [$this->attempt($id)->state, $this->attempt($id)->state_reason]);
    }

    public function test_two_attempts_on_one_record_cannot_run_together(): void // AC-FIX-005.3
    {
        $a = $this->approved();
        $this->request($a['approval'])->assertStatus(202);
        // A second approved plan for the same record (another ticket) is blocked while the first is in flight.
        $t2 = $this->makeTicket($this->f['ws'], $this->f['site'], 'investigating');
        $this->owner()->insert("INSERT INTO repair_attempts(workspace_id, ticket_id, quote_id, approval_id, connection_id, connection_secret_version, zone_id, record_id, plan, plan_fingerprint, idempotency_key, requested_by)
            SELECT workspace_id, ?, quote_id, gen_random_uuid(), connection_id, 1, zone_id, record_id, plan, plan_fingerprint, 'other-attempt', requested_by FROM repair_attempts LIMIT 0", [$t2]);
        try {
            $this->owner()->insert("INSERT INTO repair_attempts(workspace_id, ticket_id, quote_id, approval_id, connection_id, connection_secret_version, zone_id, record_id, plan, plan_fingerprint, idempotency_key, requested_by)
                SELECT workspace_id, ?, quote_id, gen_random_uuid(), connection_id, 1, zone_id, record_id, plan, plan_fingerprint, 'other-attempt', requested_by FROM repair_attempts", [$t2]);
            $this->fail('two in-flight attempts on one record');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('repair_attempts_one_active', $e->getMessage());
        }
    }

    public function test_audit_links_evidence_approval_payment_provider_and_result(): void // AC-FIX-005.4
    {
        $a = $this->approved();
        $this->propagate();
        $id = $this->request($a['approval'])->json('id');
        $this->runExecutor();
        $at = $this->attempt($id);
        $this->assertSame($a['approval'], $at->approval_id);
        $this->assertSame($a['quote'], $at->quote_id);
        $this->assertTrue((bool) $this->owner()->table('approvals')->where('id', $at->approval_id)->value('payment_verified'));
        $plan = json_decode($at->plan, true);
        $this->assertSame(self::ZONE, $plan['zone_id']);
        $refs = $this->owner()->table('repair_events')->where('attempt_id', $id)->pluck('detail')->map(fn ($d) => json_decode($d, true)['ref'] ?? null)->filter()->values();
        $this->assertGreaterThanOrEqual(2, $refs->count(), 'provider request IDs recorded');
        $this->assertCount(1, $this->auditRows('repair.requested'));
        $this->assertSame($id, $this->auditRows('repair.requested')[0]->target_id);
        $events = $this->owner()->table('ticket_events')->where('ticket_id', $this->f['ticket'])->pluck('reason')->all();
        $this->assertContains('Automated DNS fix: verified by post-fix checks', $events);
        // Events are append-only.
        try {
            DB::connection('pgsql_executor')->transaction(function ($db) use ($id) {
                $db->select("SELECT set_config('app.workspace_id', ?, true)", [$this->f['ws']]);
                $db->update("UPDATE repair_events SET kind = 'x' WHERE attempt_id = ?", [$id]);
            });
            $this->fail('repair events edited');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertMatchesRegularExpression('/append-only|denied/', $e->getMessage());
        }
    }
}
