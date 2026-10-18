<?php

namespace Tests;

use App\Models\User;
use App\Support\Net\HttpTransport;
use App\Support\Net\Resolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeHttp;
use Tests\Fakes\FakeResolver;

/**
 * Feature tests run against real PostgreSQL (upfixy_api_test) through the
 * restricted upfixy_app role with REAL bearer tokens, so RLS, triggers and the
 * token lookup function are exercised exactly as in production. Fixtures are
 * written through the owner connection. No network: DNS and HTTP are faked.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $schemaReady = false;
    protected FakeResolver $dns;
    protected FakeHttp $http;
    /** @var array<string, string> user id => plain token */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->owner();
        if (!self::$schemaReady) {
            $owner->unprepared('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
            Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);
            self::$schemaReady = true;
        }
        $owner->unprepared("DO \$\$ DECLARE t text; BEGIN
            ALTER TABLE audit_events DISABLE TRIGGER USER; ALTER TABLE quotes DISABLE TRIGGER USER;
            FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
                     AND tablename NOT IN ('migrations', 'schema_migrations', 'action_catalog') LOOP
              EXECUTE format('TRUNCATE %I RESTART IDENTITY CASCADE', t);
            END LOOP;
            ALTER TABLE audit_events ENABLE TRIGGER USER; ALTER TABLE quotes ENABLE TRIGGER USER; END \$\$");

        $this->dns = new FakeResolver();
        $this->http = new FakeHttp();
        $this->app->instance(Resolver::class, $this->dns);
        $this->app->instance(HttpTransport::class, $this->http);
        $this->tokens = [];
    }

    protected function tearDown(): void
    {
        // Close every pooled connection: roles have small connection limits (the executor allows 5).
        foreach (array_keys(config('database.connections')) as $name) {
            if (str_starts_with($name, 'pgsql')) DB::purge($name);
        }
        parent::tearDown();
    }

    protected function owner()
    {
        return DB::connection('pgsql_owner');
    }

    protected function makeUser(string $name, bool $verified = true): User
    {
        $row = $this->owner()->selectOne(
            'INSERT INTO users(email, password_hash, email_verified_at) VALUES (?, ?, ?)
             RETURNING id, email, email_verified_at, mfa_enabled_at, created_at',
            ["{$name}@test.local", password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]), $verified ? now() : null]);
        return (new User)->newFromBuilder((array) $row);
    }

    /** Turn on MFA for a user directly (fixture) and return the TOTP secret. */
    protected function enableMfa(User $user): string
    {
        $secret = (new \PragmaRX\Google2FA\Google2FA)->generateSecretKey(32);
        $this->owner()->update('UPDATE users SET mfa_secret_enc = ?, mfa_enabled_at = now() WHERE id = ?',
            [\Illuminate\Support\Facades\Crypt::encryptString($secret), $user->id]);
        $user->mfa_enabled_at = now();
        $this->tokens = array_filter($this->tokens, fn ($k) => !str_starts_with($k, $user->id), ARRAY_FILTER_USE_KEY);
        return $secret;
    }

    protected function totp(string $secret, int $offsetSteps = 0): string
    {
        $g = new \PragmaRX\Google2FA\Google2FA;
        return $g->oathTotp($secret, $g->getTimestamp() + $offsetSteps);
    }

    /** A real API token, created through the owner connection (fixture). */
    protected function tokenFor(User $user, bool $mfaFresh = false, array $abilities = ['*']): string
    {
        $key = $user->id . ($mfaFresh ? ':fresh' : '') . implode(',', $abilities);
        if (isset($this->tokens[$key])) return $this->tokens[$key];
        $secret = 'upfx_' . bin2hex(random_bytes(20));
        $id = $this->owner()->selectOne(
            "INSERT INTO personal_access_tokens(tokenable_type, tokenable_id, name, token, abilities, expires_at, created_at, updated_at, mfa_verified_at)
             VALUES ('App\\Models\\User', ?, 'test', ?, ?, now() + interval '1 day', now(), now(), ?) RETURNING id",
            [$user->id, hash('sha256', $secret), json_encode($abilities), $mfaFresh ? now() : null])->id;
        return $this->tokens[$key] = "{$id}|{$secret}";
    }

    protected function makeWorkspace(string $name, string $kind = 'agency'): string
    {
        return $this->owner()->selectOne('INSERT INTO workspaces(name, kind) VALUES (?, ?) RETURNING id', [$name, $kind])->id;
    }

    protected function addMember(string $ws, User $user, string $role): void
    {
        $this->owner()->insert('INSERT INTO memberships(workspace_id, user_id, role) VALUES (?, ?, ?)', [$ws, $user->id, $role]);
    }

    protected function makeClient(string $ws, string $name, bool $agencyMayPay = false): string
    {
        return $this->owner()->selectOne('INSERT INTO clients(workspace_id, name, agency_may_pay) VALUES (?, ?, ?) RETURNING id',
            [$ws, $name, $agencyMayPay])->id;
    }

    protected function assign(string $ws, string $client, User $user): void
    {
        $this->owner()->insert('INSERT INTO client_assignments(workspace_id, client_id, user_id) VALUES (?, ?, ?)', [$ws, $client, $user->id]);
    }

    /** Website + one active https target on the given host. Returns [websiteId, targetId]. */
    protected function makeSite(string $ws, ?string $client, string $host): array
    {
        $site = $this->owner()->selectOne('INSERT INTO websites(workspace_id, client_id, display_name) VALUES (?, ?, ?) RETURNING id', [$ws, $client, $host])->id;
        $target = $this->owner()->selectOne("INSERT INTO monitor_targets(workspace_id, website_id, scheme, host, port, state)
            VALUES (?, ?, 'https', ?, 443, 'active') RETURNING id", [$ws, $site, $host])->id;
        return [$site, $target];
    }

    /** Verified ownership of $host on $site, the production way: API challenge + worker check against fake DNS. */
    protected function verifyOwnership(User $by, string $ws, string $site, string $host): string
    {
        $claim = $this->api($by, $ws, 'POST', "websites/{$site}/claims", ['host' => $host, 'method' => 'dns_txt'])->assertCreated();
        $this->dns->txt["_upfixy-challenge.{$host}"] = [$claim->json('instructions.value')];
        $this->api($by, $ws, 'POST', "claims/{$claim->json('id')}/check")->assertStatus(202);
        $this->assertSame('verified', $this->owner()->table('ownership_claims')->where('id', $claim->json('id'))->value('status'));
        return $claim->json('id');
    }

    protected function observe(string $ws, string $target, string $kind, string $severity, int $minutesAgo = 1, int $cycle = 1): void
    {
        $this->owner()->insert("INSERT INTO observations(workspace_id, monitor_target_id, check_kind, cycle_no, severity, result_code, observed_at)
            VALUES (?, ?, ?, ?, ?, 'test', now() - make_interval(mins => ?))", [$ws, $target, $kind, $cycle, $severity, $minutesAgo]);
    }

    protected function observeAll(string $ws, string $target, string $severity = 'ok', int $minutesAgo = 1): void
    {
        foreach (['http', 'tls', 'dns'] as $k) $this->observe($ws, $target, $k, $severity, $minutesAgo);
    }

    protected function makeTicket(string $ws, string $site, string ...$states): string
    {
        $id = $this->owner()->selectOne("INSERT INTO tickets(workspace_id, website_id, title) VALUES (?, ?, 'test') RETURNING id", [$ws, $site])->id;
        foreach ($states as $s) {
            $this->owner()->update('UPDATE tickets SET state = ?::ticket_state, version = version + 1 WHERE id = ?', [$s, $id]);
        }
        return $id;
    }

    /** Make an API call as $user in workspace $ws with a real bearer token. */
    protected function api(User $user, ?string $ws, string $method, string $uri, array $data = [], bool $mfaFresh = false): TestResponse
    {
        Auth::forgetGuards(); // the request guard caches the user across requests in one test
        $headers = ['Authorization' => 'Bearer ' . $this->tokenFor($user, $mfaFresh)];
        if ($ws) $headers['X-Workspace-Id'] = $ws;
        return $this->json($method, "/api/v1/{$uri}", $data, $headers);
    }

    protected function auditRows(string $action): array
    {
        return $this->owner()->select('SELECT * FROM audit_events WHERE action = ? ORDER BY id', [$action]);
    }
}
