<?php

namespace Tests\Feature;

use App\Connectors\ConnectorError;
use App\Connectors\ConnectorHttp;
use App\Connectors\CpanelAdapter;
use App\Connectors\CurlConnectorHttp;
use App\Connectors\SyncRunner;
use App\Repairs\SecretBox;
use App\Support\Net\Resolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Fakes\FakeConnectorHttp;
use Tests\TestCase;

/**
 * M4 read-only connectors against scripted providers (no network, no pilot accounts):
 * INT-001…004, INT-006, AST-004 and AC-INT-*.
 */
class ConnectorsTest extends TestCase
{
    private const CF = 'https://cf.test/client/v4';
    private const Z1 = '11111111111111111111111111111111';
    private const Z2 = '22222222222222222222222222222222';
    private const CF_TOKEN = 'cf-read-only-test-token-0001';
    private const WHM_TOKEN = 'WHMRESELLERTESTTOKEN0001';
    private const CP_TOKEN = 'CPANELACCOUNTTESTTOKEN01';

    private array $f;
    private FakeConnectorHttp $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $k = SecretBox::keygen();
        config(['upfixy.connectors.public_key' => $k['public'], 'upfixy.connectors.keypair' => $k['keypair'], 'upfixy.connectors.cloudflare_base' => self::CF]);
        $this->provider = new FakeConnectorHttp();
        $this->app->instance(ConnectorHttp::class, $this->provider);

        $f = [];
        foreach (['olive', 'adam', 'tina', 'carl', 'bob'] as $n) $f[$n] = $this->makeUser($n);
        $f['ws'] = $this->makeWorkspace('Agency');
        $f['wsB'] = $this->makeWorkspace('Other');
        foreach ([['olive', 'owner'], ['adam', 'admin'], ['tina', 'technician'], ['carl', 'client_owner']] as [$u, $r]) $this->addMember($f['ws'], $f[$u], $r);
        $this->addMember($f['wsB'], $f['bob'], 'owner');
        $f['mitra'] = $this->makeClient($f['ws'], 'Mitra');
        $f['kala'] = $this->makeClient($f['ws'], 'Kala');
        $this->assign($f['ws'], $f['mitra'], $f['tina']);
        $this->assign($f['ws'], $f['mitra'], $f['carl']);
        foreach (['olive', 'adam', 'tina', 'carl', 'bob'] as $u) $this->enableMfa($f[$u]);
        $this->f = $f;
    }

    // ------------------------------------------------------------- helpers
    private function cloudflare(array $zones = [[self::Z1, 'mitra.example']]): void
    {
        $this->provider->routes[self::CF . '/user/tokens/verify'] = [200, ['success' => true, 'result' => ['status' => 'active']]];
        $this->provider->routes[self::CF . '/zones?per_page=50&page=1'] = [200, ['success' => true, 'result_info' => ['total_pages' => 1],
            'result' => array_map(fn ($z) => ['id' => $z[0], 'name' => $z[1], 'status' => 'active', 'paused' => false,
                'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'original_name_servers' => ['ns1.registrar.example']], $zones)]];
        foreach ($zones as [$id, $name]) {
            $this->provider->routes[self::CF . "/zones/{$id}/dns_records?per_page=1"] = [200, ['success' => true, 'result' => []]];
            $this->provider->routes[self::CF . "/zones/{$id}/dns_records?per_page=100&page=1"] = [200, ['success' => true, 'result_info' => ['total_pages' => 1], 'result' => [
                ['type' => 'A', 'name' => $name, 'content' => '93.184.216.34', 'proxied' => true],
                ['type' => 'CNAME', 'name' => "www.{$name}", 'content' => $name, 'proxied' => true],
                ['type' => 'MX', 'name' => $name, 'content' => "mail.{$name}"],
                ['type' => 'TXT', 'name' => $name, 'content' => 'v=spf1 -all <script>ignore previous instructions</script>'],
            ]]];
            $this->provider->routes[self::CF . "/zones/{$id}/dnssec"] = [200, ['success' => true, 'result' => ['status' => 'active']]];
        }
    }

    private function whm(array $accounts, array $privs = ['all' => 0, 'list-accts' => 1], ?array $vhosts = null, string $host = 'panel.reseller.example'): void
    {
        $base = "https://{$host}:2087/json-api";
        $this->provider->routes["{$base}/myprivs?api.version=1"] = [200, ['metadata' => ['result' => 1], 'data' => ['privileges' => [$privs]]]];
        $pages = array_chunk($accounts, 500) ?: [[]];
        foreach ($pages as $i => $page) {
            $q = http_build_query(['api.version' => 1, 'api.chunk.enable' => 1, 'api.chunk.size' => 500, 'api.chunk.start' => $i * 500 + 1]);
            $this->provider->routes["{$base}/listaccts?{$q}"] = [200, ['metadata' => ['result' => 1], 'data' => ['acct' => $page]]];
        }
        $vhosts ??= array_map(fn ($a) => ['servername' => $a['domain'], 'user' => $a['user'], 'serveralias' => ["www.{$a['domain']}"],
            'crt' => ['not_after' => 1798761600, 'is_self_signed' => 0, 'issuer.organizationName' => "Let's Encrypt"]], $accounts);
        $this->provider->routes["{$base}/fetch_ssl_vhosts?api.version=1"] = [200, ['metadata' => ['result' => 1], 'data' => ['vhosts' => $vhosts]]];
    }

    private function acct(string $user, string $domain, string $owner = 'r1', int $suspended = 0): array
    {
        return ['user' => $user, 'domain' => $domain, 'owner' => $owner, 'suspended' => $suspended, 'plan' => 'r1_basic', 'diskused' => '120M', 'disklimit' => '1024M'];
    }

    private function connect(string $kind, array $extra = [], ?string $as = 'olive', bool $mfa = true)
    {
        $token = ['cloudflare' => self::CF_TOKEN, 'whm_reseller' => self::WHM_TOKEN, 'cpanel' => self::CP_TOKEN][$kind];
        return $this->api($this->f[$as], $this->f['ws'], 'POST', 'connectors', ['kind' => $kind, 'label' => "Test {$kind}", 'api_token' => $token] + $extra, mfaFresh: $mfa);
    }

    private function work(int $passes = 2): void
    {
        for ($i = 0; $i < $passes; $i++) {
            // Make everything due again so each pass does the next step (verify, then sync).
            $this->owner()->update('UPDATE connectors SET next_sync_at = now() - interval \'1 second\', lease_until = NULL WHERE revoked_at IS NULL');
            app(SyncRunner::class)->runOnce();
        }
    }

    private function con(string $id): object
    {
        return $this->owner()->selectOne('SELECT * FROM connectors WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------- INT-001 / 004
    public function test_cloudflare_connects_sealed_verifies_and_syncs_read_only(): void
    {
        $this->cloudflare([[self::Z1, 'mitra.example'], [self::Z2, 'kala.example']]);
        $r = $this->connect('cloudflare')->assertStatus(202);
        $id = $r->json('id');
        $this->assertStringNotContainsString(self::CF_TOKEN, $r->getContent());
        $c = $this->con($id);
        $this->assertSame('verifying', $c->status);
        $this->assertStringStartsWith('v1:', $c->secret_sealed);
        $this->assertStringNotContainsString(self::CF_TOKEN, $c->secret_sealed);
        $this->assertSame('{zones.read,dns.read,dnssec.read}', $c->declared_capabilities);

        $this->work(1);
        $c = $this->con($id);
        $this->assertSame('active', $c->status);
        $this->assertSame('{zones.read,dns.read,dnssec.read}', $c->verified_capabilities);
        $this->assertStringContainsString('can see 2 zones', $c->warnings); // account-wide token: warn, recommend zone scoping

        $this->work(1);
        $zones = $this->owner()->select("SELECT name, facts FROM connector_assets WHERE connector_id = ? ORDER BY name COLLATE \"C\"", [$id]);
        $this->assertSame(['kala.example', 'mitra.example'], array_column($zones, 'name'));
        $facts = json_decode($zones[1]->facts, true);
        $this->assertEquals(['A' => 1, 'CNAME' => 1, 'MX' => 1, 'TXT' => 1], $facts['records']['count']);
        $this->assertSame('active', $facts['dnssec']);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $facts['name_servers']);
        $this->assertStringNotContainsString('ignore previous', $zones[1]->facts); // TXT content is never stored

        // Read-only by construction: every call was a GET to an allowlisted read path with the bearer token.
        foreach ($this->provider->calls as $call) {
            $this->assertMatchesRegularExpression('#^' . preg_quote(self::CF, '#') . '/(user/tokens/verify|zones\?|zones/[0-9a-f]{32}/(dns_records\?|dnssec$))#', $call['url']);
            $this->assertSame('Bearer ' . self::CF_TOKEN, $call['headers']['Authorization']);
        }
        // Tokens never reach events, audit rows or status text.
        $this->assertStringNotContainsString(self::CF_TOKEN, json_encode($this->owner()->select('SELECT * FROM connector_events')));
        $this->assertStringNotContainsString(self::CF_TOKEN, json_encode($this->owner()->select('SELECT * FROM audit_events')));
        $this->assertStringNotContainsString(self::CF_TOKEN, json_encode($this->api($this->f['olive'], $this->f['ws'], 'GET', "connectors/{$id}")->json()));
    }

    public function test_only_owners_and_admins_with_fresh_mfa_manage_integrations(): void
    {
        $this->cloudflare();
        $this->connect('cloudflare', mfa: false)->assertStatus(403)->assertJson(['code' => 'mfa_step_up_required']);
        $this->connect('cloudflare', as: 'tina')->assertForbidden();
        $this->connect('cloudflare', as: 'carl')->assertForbidden();
        $id = $this->connect('cloudflare', as: 'adam')->assertStatus(202)->json('id');
        foreach (['tina', 'carl'] as $u) {
            $this->api($this->f[$u], $this->f['ws'], 'GET', 'connectors')->assertForbidden();
            $this->api($this->f[$u], $this->f['ws'], 'GET', "connectors/{$id}")->assertForbidden();
        }
        // Other workspace: not found, never "forbidden" (no existence leak).
        $this->api($this->f['bob'], $this->f['wsB'], 'GET', "connectors/{$id}")->assertNotFound();
        $this->api($this->f['bob'], $this->f['wsB'], 'POST', "connectors/{$id}/revoke", [], mfaFresh: true)->assertNotFound();
        $this->assertSame([], $this->api($this->f['bob'], $this->f['wsB'], 'GET', 'connectors')->json('data'));
        // Revoke and rotate need fresh MFA too.
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/revoke")->assertStatus(403);
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/rotate", ['api_token' => 'another-test-token-000002'])->assertStatus(403);
    }

    public function test_the_api_role_can_never_read_a_sealed_token(): void
    {
        $this->cloudflare();
        $this->connect('cloudflare');
        \App\Support\TenantContext::apply($this->f['olive']->id, $this->f['ws']);
        try {
            \Illuminate\Support\Facades\DB::connection('pgsql')->transaction(fn () => \Illuminate\Support\Facades\DB::select('SELECT secret_sealed FROM connectors'));
            $this->fail('the API role read a sealed token');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
        } finally {
            \App\Support\TenantContext::clear();
        }
    }

    public function test_inactive_or_refused_cloudflare_token_is_rejected_and_destroyed(): void
    {
        $this->cloudflare();
        $this->provider->routes[self::CF . '/user/tokens/verify'] = [401, ['success' => false]];
        $id = $this->connect('cloudflare')->json('id');
        $this->work(1);
        $c = $this->con($id);
        $this->assertSame('rejected', $c->status);
        $this->assertSame('rejected', $c->secret_sealed);
        $this->assertSame(0, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM connector_assets')->n);
        // A rejected connection never syncs again.
        $before = count($this->provider->calls);
        $this->work(1);
        $this->assertSame($before, count($this->provider->calls));
    }

    public function test_a_selected_zone_outside_the_token_or_workspace_is_refused_without_metadata(): void // AC-INT-004.1
    {
        $this->cloudflare([[self::Z1, 'mitra.example']]);
        $mine = $this->connect('cloudflare')->json('id');
        $this->work(2);
        // Workspace B connects its own token that sees Z2.
        $this->cloudflare([[self::Z2, 'secret-client.example']]);
        $theirs = $this->api($this->f['bob'], $this->f['wsB'], 'POST', 'connectors',
            ['kind' => 'cloudflare', 'label' => 'B', 'api_token' => 'other-workspace-token-0001'], mfaFresh: true)->json('id');
        $this->work(2);
        $z2 = $this->owner()->selectOne('SELECT id FROM connector_assets WHERE connector_id = ?', [$theirs])->id;
        // Selecting Z2 through my connection: same 404 as an id that does not exist; nothing about Z2 in the answer.
        $r = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$mine}/import", ['asset_ids' => [$z2]])->assertNotFound();
        $this->assertStringNotContainsString('secret-client', $r->getContent());
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$mine}/import", ['asset_ids' => [(string) Str::uuid()]])->assertNotFound();
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$theirs}/import", ['asset_ids' => [$z2]])->assertNotFound();
        $this->assertSame(0, (int) $this->owner()->selectOne("SELECT count(*) AS n FROM websites WHERE workspace_id = ?", [$this->f['ws']])->n);
    }

    // ---------------------------------------------------------------- INT-002
    public function test_whm_root_equivalent_token_is_refused_and_nothing_is_kept(): void // AC-INT-002.1/.3/.4, D-04
    {
        $this->whm([$this->acct('alpha', 'alpha.example', 'root'), $this->acct('beta', 'beta.example', 'r9')], ['all' => 1, 'list-accts' => 1]);
        $id = $this->connect('whm_reseller', ['host' => 'panel.reseller.example', 'username' => 'r1'])->json('id');
        $this->work(1);
        $c = $this->con($id);
        $this->assertSame('rejected', $c->status);
        $this->assertStringContainsString('root-equivalent', $c->status_reason);
        $this->assertSame('rejected', $c->secret_sealed);
        $this->assertSame(0, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM connector_assets')->n);
        $this->assertStringNotContainsString('beta.example', json_encode($this->owner()->select('SELECT * FROM connector_events')));
    }

    public function test_whm_token_that_sees_another_resellers_accounts_is_refused(): void // AC-INT-002.2/.3
    {
        $this->whm([$this->acct('alpha', 'alpha.example'), $this->acct('other', 'r2-customer.example', 'r2')]);
        $id = $this->connect('whm_reseller', ['host' => 'panel.reseller.example', 'username' => 'r1'])->json('id');
        $this->work(1);
        $c = $this->con($id);
        $this->assertSame('rejected', $c->status);
        $this->assertStringContainsString('scope could not be verified', $c->status_reason);
        $this->assertStringNotContainsString('r2-customer', json_encode($this->owner()->select('SELECT * FROM connector_assets')));
    }

    public function test_cross_reseller_account_on_a_later_page_is_still_caught(): void // pagination leakage, §17.3
    {
        $accounts = [];
        for ($i = 0; $i < 500; $i++) $accounts[] = $this->acct("u{$i}", "site{$i}.example");
        $accounts[] = $this->acct('leak', 'r2-customer.example', 'r2');
        $this->whm($accounts);
        $id = $this->connect('whm_reseller', ['host' => 'panel.reseller.example', 'username' => 'r1'])->json('id');
        $this->work(1);
        $this->assertSame('rejected', $this->con($id)->status);
        $this->assertSame(0, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM connector_assets')->n);
    }

    public function test_whm_reseller_imports_only_its_accounts_and_rechecks_scope_every_sync(): void
    {
        $this->whm([$this->acct('r1', 'reseller-home.example', 'root'), $this->acct('alpha', 'alpha.example'), $this->acct('beta', 'beta.example', 'r1', 1)]);
        $id = $this->connect('whm_reseller', ['host' => 'panel.reseller.example', 'username' => 'r1'])->json('id');
        $this->work(2);
        $c = $this->con($id);
        $this->assertSame('active', $c->status);
        $this->assertSame('{accounts.read,domains.read,ssl.read}', $c->verified_capabilities);
        $assets = $this->owner()->select("SELECT kind, name, parent_external_id, facts FROM connector_assets WHERE connector_id = ? ORDER BY kind, name COLLATE \"C\"", [$id]);
        $this->assertSame(['account:alpha.example', 'account:beta.example', 'account:reseller-home.example',
            'domain:alpha.example', 'domain:beta.example', 'domain:reseller-home.example'], array_map(fn ($a) => "{$a->kind}:{$a->name}", $assets));
        $this->assertTrue(json_decode($assets[1]->facts, true)['suspended']);
        $this->assertSame('2027-01-01T00:00:00+00:00', json_decode($assets[3]->facts, true)['ssl']['not_after']);
        foreach ($this->provider->calls as $call) $this->assertSame('whm r1:' . self::WHM_TOKEN, $call['headers']['Authorization']);

        // The token's privileges are widened at the provider: the next sync refuses and wipes everything.
        $this->whm([$this->acct('alpha', 'alpha.example')], ['all' => 1, 'list-accts' => 1]);
        $this->work(1);
        $c = $this->con($id);
        $this->assertSame('rejected', $c->status);
        $this->assertSame('rejected', $c->secret_sealed);
        $this->assertSame(0, (int) $this->owner()->selectOne("SELECT count(*) AS n FROM connector_assets WHERE connector_id = ? AND (gone_at IS NULL OR facts <> '{}')", [$id])->n);
    }

    public function test_ssl_vhost_of_a_foreign_account_is_a_scope_violation(): void
    {
        $this->whm([$this->acct('alpha', 'alpha.example')], vhosts: [
            ['servername' => 'alpha.example', 'user' => 'alpha', 'crt' => []],
            ['servername' => 'stranger.example', 'user' => 'someone_else', 'crt' => []],
        ]);
        $id = $this->connect('whm_reseller', ['host' => 'panel.reseller.example', 'username' => 'r1'])->json('id');
        $this->work(1);
        $this->assertSame('rejected', $this->con($id)->status);
    }

    // ---------------------------------------------------------------- INT-003
    public function test_cpanel_account_reads_only_allowlisted_functions(): void // AC-INT-003.1
    {
        $base = 'https://cpanel.host.example:2083/execute';
        $this->provider->routes["{$base}/DomainInfo/list_domains"] = [200, ['status' => 1, 'data' => [
            'main_domain' => 'kala.example', 'addon_domains' => ['kala-shop.example'], 'parked_domains' => [], 'sub_domains' => ['blog.kala.example', 'not a host']]]];
        $this->provider->routes["{$base}/SSL/installed_hosts"] = [200, ['status' => 1, 'data' => [
            ['servername' => 'kala.example', 'certificate' => ['not_after' => 1798761600, 'is_self_signed' => 1, 'domains' => ['kala.example', 'www.kala.example']]]]]];
        $id = $this->connect('cpanel', ['host' => 'cpanel.host.example', 'username' => 'kala'])->json('id');
        $this->work(2);
        $this->assertSame('{domains.read,ssl.read}', $this->con($id)->verified_capabilities);
        $names = array_column($this->owner()->select("SELECT name FROM connector_assets WHERE connector_id = ? AND kind = 'domain'", [$id]), 'name');
        sort($names, SORT_STRING);   // byte order: database collations differ between machines
        $this->assertSame(['blog.kala.example', 'kala-shop.example', 'kala.example'], $names); // invalid names dropped
        foreach ($this->provider->calls as $call) {
            $this->assertContains(substr($call['url'], strlen($base) + 1), CpanelAdapter::ALLOWED);
            $this->assertSame('cpanel kala:' . self::CP_TOKEN, $call['headers']['Authorization']);
        }
        // The adapter cannot be steered to a write: there is no verb, and functions outside the list are refused.
        $m = new \ReflectionMethod(CpanelAdapter::class, 'call');
        try {
            $m->invoke(new CpanelAdapter($this->provider), 'Fileman/delete_files', ['host' => 'cpanel.host.example', 'user' => 'kala', 'token' => 'x']);
            $this->fail('non-allowlisted function was dispatched');
        } catch (ConnectorError $e) {
            $this->assertSame('blocked', $e->kind);
        }
        // Tampered input cannot reshape the URL.
        foreach (['kala/../execute/Fileman', 'kala?x=1', 'Kala Shop'] as $bad) {
            $this->connect('cpanel', ['host' => 'cpanel.host.example', 'username' => $bad])->assertStatus(422);
        }
        foreach (['10.0.0.5', 'host.example/path', 'https://host.example', 'host.example:2083'] as $bad) {
            $this->connect('cpanel', ['host' => $bad, 'username' => 'kala'])->assertStatus(422);
        }
    }

    public function test_panel_hosts_that_resolve_to_private_addresses_are_refused(): void // MON-006 applies to providers too
    {
        $resolver = new class implements Resolver {
            public array $map = [];
            public function addresses(string $host): array { return $this->map[$host] ?? []; }
            public function txt(string $name): array { return []; }
        };
        $resolver->map = ['internal.example' => ['10.0.0.5'], 'meta.example' => ['169.254.169.254'], 'mixed.example' => ['93.184.216.34', '127.0.0.1']];
        $http = new CurlConnectorHttp($resolver);
        foreach (['https://internal.example:2087/json-api/myprivs', 'https://meta.example:2083/execute/x', 'https://mixed.example/', 'http://ok.example/',
                     'https://ok.example:8443/', 'https://user:pw@ok.example/'] as $url) {
            try {
                $http->get($url, []);
                $this->fail("{$url} was fetched");
            } catch (ConnectorError $e) {
                $this->assertContains($e->kind, ['blocked', 'network'], $url);
            }
        }
    }

    // ---------------------------------------------------------------- INT-006
    public function test_import_needs_explicit_selection_and_flags_ambiguous_client_mapping(): void // AC-INT-006.1/.2
    {
        // An existing website already belongs to client Kala.
        [$existing] = $this->makeSite($this->f['ws'], $this->f['kala'], 'beta.example');
        $this->whm([$this->acct('alpha', 'alpha.example'), $this->acct('beta', 'beta.example'), $this->acct('gamma', 'gamma.example')]);
        $id = $this->connect('whm_reseller', ['host' => 'panel.reseller.example', 'username' => 'r1'])->json('id');
        $this->work(2);
        // Preview only: nothing created by syncing.
        $this->assertSame(1, (int) $this->owner()->selectOne('SELECT count(*) AS n FROM websites WHERE workspace_id = ?', [$this->f['ws']])->n);
        $preview = $this->api($this->f['olive'], $this->f['ws'], 'GET', "connectors/{$id}")->assertOk()->json('assets');
        $byName = fn ($kind, $name) => collect($preview)->first(fn ($a) => $a['kind'] === $kind && $a['name'] === $name)['id'];
        $this->assertSame($existing, collect($preview)->first(fn ($a) => $a['name'] === 'beta.example')['match_website_id']);

        $r = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/import",
            ['asset_ids' => [$byName('domain', 'alpha.example'), $byName('domain', 'beta.example')], 'client_id' => $this->f['mitra']])->assertOk();
        $this->assertSame(['alpha.example'], array_column($r->json('imported'), 'name'));
        $this->assertSame(['beta.example'], array_column($r->json('needs_review'), 'name'));
        // gamma was not selected: not created. beta was not re-pointed to Mitra.
        $sites = $this->owner()->select('SELECT w.display_name, w.client_id FROM websites w WHERE workspace_id = ? ORDER BY display_name COLLATE "C"', [$this->f['ws']]);
        $this->assertSame(['alpha.example', 'beta.example'], array_column($sites, 'display_name'));
        $this->assertSame([$this->f['mitra'], $this->f['kala']], array_column($sites, 'client_id'));
        $beta = $this->owner()->selectOne("SELECT import_state, website_id FROM connector_assets WHERE id = ?", [$byName('domain', 'beta.example')]);
        $this->assertSame('needs_review', $beta->import_state);
        $this->assertNull($beta->website_id);
        // Importing never claims ownership (D-07).
        $this->assertSame(0, (int) $this->owner()->selectOne("SELECT count(*) AS n FROM ownership_claims WHERE status = 'verified'")->n);
        // Same client, no conflict: linked to the existing site rather than duplicated.
        $r = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/import", ['asset_ids' => [$byName('domain', 'beta.example')], 'client_id' => $this->f['kala']])->assertOk();
        $this->assertSame($existing, $r->json('linked.0.website_id'));
        // Deleting an imported website puts the item back in the preview (no broken link).
        $alpha = $this->owner()->selectOne("SELECT id, website_id FROM connector_assets WHERE id = ?", [$byName('domain', 'alpha.example')]);
        $this->owner()->delete("DELETE FROM websites WHERE id = ?", [$alpha->website_id]);
        $this->assertSame('discovered', $this->owner()->selectOne('SELECT import_state FROM connector_assets WHERE id = ?', [$alpha->id])->import_state);
        // Non-admins cannot import.
        $this->api($this->f['tina'], $this->f['ws'], 'POST', "connectors/{$id}/import", ['asset_ids' => [$byName('domain', 'gamma.example')]])->assertForbidden();
    }

    // ---------------------------------------------- AST-004 asset graph, D-07
    public function test_website_shows_provider_links_but_facts_need_ownership_proof(): void
    {
        $this->cloudflare([[self::Z1, 'mitra.example']]);
        $id = $this->connect('cloudflare')->json('id');
        $this->work(2);
        $zone = $this->owner()->selectOne('SELECT id FROM connector_assets WHERE connector_id = ?', [$id])->id;
        $site = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/import", ['asset_ids' => [$zone], 'client_id' => $this->f['mitra']])
            ->json('imported.0.website_id');

        $links = $this->api($this->f['tina'], $this->f['ws'], 'GET', "websites/{$site}/providers")->assertOk()->json('data');
        $this->assertSame('cloudflare', $links[0]['provider']);
        $this->assertNull($links[0]['facts']);       // linked, but no proof yet
        $this->assertFalse($links[0]['private_access']);
        $this->assertFalse($links[0]['stale']);

        $this->verifyOwnership($this->f['olive'], $this->f['ws'], $site, 'mitra.example');
        $links = $this->api($this->f['carl'], $this->f['ws'], 'GET', "websites/{$site}/providers")->assertOk()->json('data');
        $this->assertSame('active', $links[0]['facts']['dnssec']);
        // Someone who can't see the website gets nothing.
        $this->api($this->f['bob'], $this->f['wsB'], 'GET', "websites/{$site}/providers")->assertNotFound();
    }

    // ---------------------------------------------------- revocation, AC-INT-001
    public function test_revocation_stops_private_sync_marks_data_stale_and_public_checks_continue(): void // AC-INT-001.1/.2
    {
        $this->cloudflare([[self::Z1, 'mitra.example']]);
        $id = $this->connect('cloudflare')->json('id');
        $this->work(2);
        $zone = $this->owner()->selectOne('SELECT id FROM connector_assets WHERE connector_id = ?', [$id])->id;
        $site = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/import", ['asset_ids' => [$zone], 'client_id' => $this->f['mitra']])
            ->json('imported.0.website_id');

        // The worker claims a sync, then the owner revokes before it reports back.
        $this->owner()->update("UPDATE connectors SET next_sync_at = now() - interval '1 second' WHERE id = ?", [$id]);
        $claim = \Illuminate\Support\Facades\DB::connection('pgsql_worker')->selectOne('SELECT * FROM worker_claim_connectors(10, 60)');
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/revoke", [], mfaFresh: true)->assertOk();
        $ok = \Illuminate\Support\Facades\DB::connection('pgsql_worker')->selectOne('SELECT worker_connector_synced(?, ?, ?::jsonb) AS ok',
            [$claim->id, $claim->secret_version, json_encode([['kind' => 'zone', 'external_id' => 'x', 'name' => 'injected.example', 'facts' => []]])])->ok;
        $this->assertFalse($ok);
        $this->assertNull($this->owner()->selectOne("SELECT 1 AS x FROM connector_assets WHERE name = 'injected.example'"));

        $c = $this->con($id);
        $this->assertSame(['revoked', 'revoked'], [$c->status, $c->secret_sealed]);
        $calls = count($this->provider->calls);
        $this->work(1);
        $this->assertSame($calls, count($this->provider->calls)); // no provider call after revoke

        $links = $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$site}/providers")->json('data');
        $this->assertTrue($links[0]['stale']);
        $this->assertSame('Connection revoked', $links[0]['stale_reason']);
        // Public monitoring of the imported website is untouched.
        $t = $this->owner()->selectOne('SELECT state, next_check_at FROM monitor_targets WHERE website_id = ?', [$site]);
        $this->assertSame('pending_validation', $t->state);
        $this->assertNotEmpty(\Illuminate\Support\Facades\DB::connection('pgsql_worker')->select('SELECT * FROM worker_claim_due(10, 10, 60)'));
    }

    public function test_rotation_invalidates_in_flight_work_and_reverifies(): void
    {
        $this->cloudflare();
        $id = $this->connect('cloudflare')->json('id');
        $this->work(1);
        $this->owner()->update("UPDATE connectors SET next_sync_at = now() - interval '1 second' WHERE id = ?", [$id]);
        $claim = \Illuminate\Support\Facades\DB::connection('pgsql_worker')->selectOne('SELECT * FROM worker_claim_connectors(10, 60)');
        $v = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/rotate", ['api_token' => 'rotated-read-token-000002'], mfaFresh: true)
            ->assertOk()->json('secret_version');
        $this->assertSame($claim->secret_version + 1, $v);
        $this->assertFalse(\Illuminate\Support\Facades\DB::connection('pgsql_worker')
            ->selectOne('SELECT worker_connector_failed(?, ?, ?, ?) AS ok', [$id, $claim->secret_version, 'network', 'x'])->ok);
        $this->assertSame('verifying', $this->con($id)->status);
        $this->work(1);
        $this->assertSame('active', $this->con($id)->status);
        $this->assertSame('Bearer rotated-read-token-000002', end($this->provider->calls)['headers']['Authorization']);
    }

    // ------------------------------------------------- failure isolation §17.3
    public function test_provider_outage_backs_off_and_only_its_own_data_goes_stale(): void
    {
        $this->cloudflare([[self::Z1, 'mitra.example']]);
        $id = $this->connect('cloudflare')->json('id');
        $this->work(2);
        $zone = $this->owner()->selectOne('SELECT id FROM connector_assets WHERE connector_id = ?', [$id])->id;
        $site = $this->api($this->f['olive'], $this->f['ws'], 'POST', "connectors/{$id}/import", ['asset_ids' => [$zone], 'client_id' => $this->f['mitra']])
            ->json('imported.0.website_id');

        $this->provider->routes[self::CF . '/zones?per_page=50&page=1'] = 'network';
        $delays = [];
        for ($i = 0; $i < 3; $i++) {
            $this->work(1);
            $c = $this->con($id);
            $delays[] = strtotime($c->next_sync_at) - time();
        }
        $this->assertSame('error', $c->status);
        $this->assertSame(3, (int) $c->consecutive_failures);
        $this->assertTrue($delays[0] < $delays[1] && $delays[1] < $delays[2], json_encode($delays)); // exponential backoff
        $this->assertLessThanOrEqual(21600, max($delays));
        $links = $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$site}/providers")->json('data');
        $this->assertTrue($links[0]['stale']);
        $this->assertSame('Provider not reachable', $links[0]['stale_reason']);
        // Monitoring data and targets are untouched by a connector outage.
        $this->assertSame('pending_validation', $this->owner()->selectOne('SELECT state FROM monitor_targets WHERE website_id = ?', [$site])->state);

        // Transient errors while verifying a new connection keep it "verifying" for a retry; they never reject it.
        $this->cloudflare();
        $this->provider->routes[self::CF . '/user/tokens/verify'] = 'server_error';
        $new = $this->api($this->f['adam'], $this->f['ws'], 'POST', 'connectors', ['kind' => 'cloudflare', 'label' => 'Second', 'api_token' => 'another-read-token-00003'], mfaFresh: true)->json('id');
        $this->work(1);
        $this->assertSame('verifying', $this->con($new)->status);
        $this->assertStringStartsWith('v1:', $this->con($new)->secret_sealed);
    }

    public function test_connector_worker_cannot_reach_repair_credentials_or_tenant_tables(): void
    {
        $w = \Illuminate\Support\Facades\DB::connection('pgsql_worker');
        foreach (['SELECT secret_sealed FROM dns_connections', 'SELECT secret_sealed FROM connectors', 'SELECT * FROM connector_assets',
                     'SELECT executor_sealed_secret(gen_random_uuid(), 1)'] as $sql) {
            try {
                $w->select($sql);
                $this->fail("worker could run: {$sql}");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode(), $sql);
            }
        }
    }
}
