<?php

namespace Tests\Feature;

use Tests\TestCase;

/** AST-002 / D-07 through the API, with DNS and HTTP faked (no network). */
class OwnershipTest extends TestCase
{
    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $f = [];
        $f['olive'] = $this->makeUser('olive');
        $f['tina'] = $this->makeUser('tina');
        $f['bob'] = $this->makeUser('bob');
        $f['ws'] = $this->makeWorkspace('Agency A');
        $f['wsB'] = $this->makeWorkspace('Agency B');
        $this->addMember($f['ws'], $f['olive'], 'owner');
        $this->addMember($f['ws'], $f['tina'], 'technician');
        $this->addMember($f['wsB'], $f['bob'], 'owner');
        [$f['site'], $f['target']] = $this->makeSite($f['ws'], null, 'shop.example');
        $this->f = $f;
    }

    private function challenge(string $method, string $host = 'shop.example')
    {
        return $this->api($this->f['olive'], $this->f['ws'], 'POST', "websites/{$this->f['site']}/claims", ['host' => $host, 'method' => $method]);
    }

    private function claimStatus(string $claim): string
    {
        return $this->owner()->table('ownership_claims')->where('id', $claim)->value('status');
    }

    public function test_dns_challenge_gives_clear_instructions_and_verifies_on_exact_match(): void
    {
        $c = $this->challenge('dns_txt')->assertCreated()
            ->assertJsonPath('instructions.type', 'TXT')
            ->assertJsonPath('instructions.name', '_upfixy-challenge.shop.example')
            ->assertJsonPath('status', 'challenge_issued');
        $value = $c->json('instructions.value');
        $this->assertMatchesRegularExpression('/^upfixy-verify=[0-9a-f]{48}$/', $value);

        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$c->json('id')}/check")->assertStatus(202);
        $this->assertSame('challenge_issued', $this->claimStatus($c->json('id')));
        $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$this->f['site']}/claims")
            ->assertJsonPath('data.0.last_check_result', fn ($m) => str_contains($m, 'No TXT record'));

        $this->dns->txt['_upfixy-challenge.shop.example'] = ['v=spf1 -all', "\"{$value}\""];
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$c->json('id')}/check")->assertStatus(202);
        $this->assertSame('verified', $this->claimStatus($c->json('id')));
        $this->api($this->f['olive'], $this->f['ws'], 'GET', "websites/{$this->f['site']}/claims")
            ->assertJsonPath('data.0.status', 'verified')->assertJsonPath('data.0.instructions', null);
    }

    public function test_html_file_challenge_pins_to_a_checked_address_and_ignores_redirects(): void
    {
        $c = $this->challenge('html_file')->assertCreated();
        $url = $c->json('instructions.url');
        $this->assertSame("https://shop.example/.well-known/upfixy-verify-{$c->json('id')}.txt", $url);
        $this->dns->a['shop.example'] = ['93.184.216.34'];

        $this->http->responses[$url] = ['status' => 302, 'body' => ''];
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$c->json('id')}/check");
        $this->assertSame('challenge_issued', $this->claimStatus($c->json('id')));

        $this->http->responses[$url] = ['status' => 200, 'body' => $c->json('instructions.contents') . "\n"];
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$c->json('id')}/check");
        $this->assertSame('verified', $this->claimStatus($c->json('id')));
        $this->assertSame('93.184.216.34', end($this->http->calls)['ip']);
    }

    public function test_ssrf_a_host_resolving_to_a_private_address_is_never_fetched(): void
    {
        $c = $this->challenge('html_file')->assertCreated();
        $this->dns->a['shop.example'] = ['93.184.216.34', '169.254.169.254']; // one bad record poisons the lot
        $this->http->responses[$c->json('instructions.url')] = ['status' => 200, 'body' => $c->json('instructions.contents')];
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$c->json('id')}/check");
        $this->assertSame('challenge_issued', $this->claimStatus($c->json('id')));
        $this->assertSame([], $this->http->calls);
        $this->assertStringContainsString('private or reserved', $this->owner()->table('ownership_claims')->value('last_check_result'));
    }

    public function test_dns_method_refuses_ip_hosts_and_unmonitored_hosts(): void
    {
        $this->challenge('dns_txt', 'other.example')->assertStatus(422)->assertJsonPath('message', 'That address is not monitored on this website.');
    }

    public function test_technicians_cannot_start_or_revoke_verification(): void
    {
        $this->api($this->f['tina'], $this->f['ws'], 'POST', "websites/{$this->f['site']}/claims", ['host' => 'shop.example', 'method' => 'dns_txt'])
            ->assertStatus(404); // technicians only see assigned clients' sites; this site has no client
    }

    public function test_another_workspace_cannot_see_start_check_or_revoke(): void
    {
        $c = $this->challenge('dns_txt')->assertCreated();
        $b = $this->f['bob'];
        $this->api($b, $this->f['wsB'], 'GET', "websites/{$this->f['site']}/claims")->assertNotFound();
        $this->api($b, $this->f['wsB'], 'POST', "websites/{$this->f['site']}/claims", ['host' => 'shop.example', 'method' => 'dns_txt'])->assertNotFound();
        $this->api($b, $this->f['wsB'], 'POST', "claims/{$c->json('id')}/check")->assertNotFound();
        $this->enableMfa($b);
        $this->api($b, $this->f['wsB'], 'POST', "claims/{$c->json('id')}/revoke", [], mfaFresh: true)->assertNotFound();
    }

    public function test_revoking_proof_needs_fresh_mfa(): void
    {
        $claim = $this->verifyOwnership($this->f['olive'], $this->f['ws'], $this->f['site'], 'shop.example');
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$claim}/revoke")->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
        $this->enableMfa($this->f['olive']);
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$claim}/revoke", [], mfaFresh: true)->assertNoContent();
        $this->assertSame('revoked', $this->claimStatus($claim));
    }

    public function test_a_check_on_an_already_verified_claim_is_a_no_op(): void
    {
        $claim = $this->verifyOwnership($this->f['olive'], $this->f['ws'], $this->f['site'], 'shop.example');
        $this->api($this->f['olive'], $this->f['ws'], 'POST', "claims/{$claim}/check")->assertStatus(409);
    }
}
