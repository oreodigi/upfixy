<?php

namespace Tests\Feature;

use Tests\TestCase;

/** ACC-001, AST-001, US-001: a solo owner adds a site with no credentials. */
class OnboardingTest extends TestCase
{
    public function test_us001_solo_signup_with_real_token_then_add_website(): void
    {
        $reg = $this->postJson('/api/v1/auth/register', [
            'email' => 'sam@test.local', 'password' => 'a long enough password',
            'account_type' => 'solo', 'workspace_name' => "Sam's sites",
        ])->assertCreated();
        $token = $reg->json('token');
        $ws = $reg->json('workspace_id');

        $this->withToken($token)->getJson('/api/v1/workspaces')
            ->assertOk()->assertJsonPath('data.0.role', 'owner')->assertJsonPath('data.0.kind', 'solo');

        $this->withToken($token)->withHeader('X-Workspace-Id', $ws)
            ->postJson('/api/v1/websites', ['url' => 'Shop.Example.com'])
            ->assertCreated()
            ->assertJsonPath('target.host', 'shop.example.com')
            ->assertJsonPath('target.port', 443)
            ->assertJsonPath('monitor_state', 'pending_validation');

        // Before any check runs the site is not "Healthy" (MON-007).
        $this->withToken($token)->withHeader('X-Workspace-Id', $ws)->getJson('/api/v1/boards/websites')
            ->assertOk()->assertJsonPath('data.0.lane', 'needs_setup')
            ->assertJsonPath('data.0.coverage.backup', 'not_connected');
    }

    public function test_weak_password_and_duplicate_email_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'a@test.local', 'password' => 'short',
            'account_type' => 'solo', 'workspace_name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->makeUser('taken');
        $this->postJson('/api/v1/auth/register', ['email' => 'taken@test.local', 'password' => 'a long enough password',
            'account_type' => 'agency', 'workspace_name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_returns_token_and_bad_password_does_not(): void
    {
        $this->makeUser('lin');
        $this->postJson('/api/v1/auth/login', ['email' => 'lin@test.local', 'password' => 'correct horse battery'])
            ->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/login', ['email' => 'lin@test.local', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/workspaces')->assertUnauthorized();
    }

    public function test_ac_ast_001_2_metadata_and_private_addresses_rejected_with_plain_reason(): void
    {
        $u = $this->makeUser('sam');
        $ws = $this->makeWorkspace('Solo', 'solo');
        $this->addMember($ws, $u, 'owner');
        foreach (['http://169.254.169.254/latest/meta-data/', 'http://0x7f.1', 'http://[::ffff:10.0.0.1]', 'http://localhost'] as $url) {
            $this->api($u, $ws, 'POST', 'websites', ['url' => $url])->assertStatus(422)
                ->assertJsonPath('errors.url.0', fn ($m) => str_contains($m, 'cannot be monitored'));
        }
        $this->assertSame(0, $this->owner()->table('websites')->count());
    }

    public function test_ac_ast_001_3_apex_and_www_are_separate_and_exact_duplicates_conflict(): void
    {
        $u = $this->makeUser('sam');
        $ws = $this->makeWorkspace('Solo', 'solo');
        $this->addMember($ws, $u, 'owner');
        $this->api($u, $ws, 'POST', 'websites', ['url' => 'example.com'])->assertCreated();
        $this->api($u, $ws, 'POST', 'websites', ['url' => 'www.example.com'])->assertCreated();
        $this->api($u, $ws, 'POST', 'websites', ['url' => 'https://example.com/'])->assertStatus(409);
        $this->assertEqualsCanonicalizing(['example.com', 'www.example.com'],
            $this->owner()->table('monitor_targets')->pluck('host')->all());
    }

    public function test_query_strings_are_dropped_and_the_user_is_told(): void
    {
        $u = $this->makeUser('sam');
        $ws = $this->makeWorkspace('Solo', 'solo');
        $this->addMember($ws, $u, 'owner');
        $this->api($u, $ws, 'POST', 'websites', ['url' => 'https://example.com/shop?token=abc'])
            ->assertCreated()->assertJsonPath('target.path', '/shop')
            ->assertJsonPath('note', 'The query string was removed; only the page path is monitored.');
    }
}
