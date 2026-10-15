<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests run against real PostgreSQL (upfixy_api_test) through the
 * restricted upfixy_app role, so RLS and triggers are exercised exactly as
 * in production. Fixtures are written through the owner connection.
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $schemaReady = false;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = DB::connection('pgsql_owner');
        if (!self::$schemaReady) {
            $owner->unprepared('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
            Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);
            self::$schemaReady = true;
        }
        $tables = $owner->table('pg_tables')->where('schemaname', 'public')
            ->whereNotIn('tablename', ['migrations', 'schema_migrations'])->pluck('tablename')->all();
        $owner->unprepared('ALTER TABLE audit_events DISABLE TRIGGER USER;'
            . 'TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE;'
            . 'ALTER TABLE audit_events ENABLE TRIGGER USER;');
    }

    protected function owner()
    {
        return DB::connection('pgsql_owner');
    }

    protected function makeUser(string $name, bool $verified = true): User
    {
        $id = $this->owner()->selectOne("INSERT INTO users(email, password_hash, email_verified_at) VALUES (?, ?, ?) RETURNING id",
            ["{$name}@test.local", password_hash('correct horse battery', PASSWORD_BCRYPT), $verified ? now() : null])->id;
        return User::findOrFail($id);
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

    /** Make an API call as $user in workspace $ws. */
    protected function api(User $user, ?string $ws, string $method, string $uri, array $data = []): TestResponse
    {
        Sanctum::actingAs($user);
        $headers = $ws ? ['X-Workspace-Id' => $ws] : [];
        return $this->json($method, "/api/v1/{$uri}", $data, $headers);
    }

    protected function auditRows(string $action): array
    {
        return $this->owner()->select('SELECT * FROM audit_events WHERE action = ? ORDER BY id', [$action]);
    }
}
