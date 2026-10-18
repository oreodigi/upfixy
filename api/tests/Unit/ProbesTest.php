<?php

namespace Tests\Unit;

use App\Monitoring\CycleRunner;
use App\Monitoring\DnsProbe;
use App\Monitoring\HttpProbe;
use App\Monitoring\TlsProbe;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeDnsClient;
use Tests\Fakes\FakeFetcher;
use Tests\Fakes\FakeProbeHealth;
use Tests\Fakes\FakeTlsInspector;

/** MON-001..003, MON-006, §17.1/§17.2 classification, without network. */
class ProbesTest extends TestCase
{
    private const R = ['1.1.1.1', '8.8.8.8', '9.9.9.9'];
    private FakeDnsClient $dns;
    private FakeFetcher $http;
    private FakeTlsInspector $tls;

    protected function setUp(): void
    {
        $this->dns = new FakeDnsClient();
        $this->http = new FakeFetcher();
        $this->tls = new FakeTlsInspector();
    }

    private function dnsProbe(): DnsProbe { return new DnsProbe($this->dns, self::R); }
    private function httpProbe(): HttpProbe { return new HttpProbe($this->http, $this->dnsProbe()); }

    // ------------------------------------------------------------------ DNS
    public function test_dns_ok_requires_agreement_and_returns_only_checked_addresses(): void
    {
        $this->dns->set('shop.example', ['93.184.216.34', '2606:2800:220:1::1']);
        [$r, $ips] = $this->dnsProbe()->probe('shop.example');
        $this->assertSame(['ok', 'DNS_OK'], [$r->severity, $r->code]);
        $this->assertSame(['93.184.216.34', '2606:2800:220:1::1'], $ips);
        $this->assertSame('not_validated', $r->detail['dnssec']);
        $this->assertArrayHasKey('records', $r->detail);
    }

    public function test_dns_nxdomain_from_every_resolver_is_a_failure(): void
    {
        $this->dns->rcode('gone.example', 'NXDOMAIN');
        [$r, $ips] = $this->dnsProbe()->probe('gone.example');
        $this->assertSame(['critical', 'target_failure', 'DNS_NXDOMAIN'], [$r->severity, $r->outcome, $r->code]);
        $this->assertSame([], $ips);
    }

    public function test_one_resolver_silent_and_others_failing_is_disagreement_not_a_cause(): void // AC-MON-003.1
    {
        $this->dns->rcode('flaky.example', 'SERVFAIL');
        $this->dns->rcode('flaky.example', 'TIMEOUT', '8.8.8.8');
        [$r] = $this->dnsProbe()->probe('flaky.example');
        $this->assertSame(['unknown', 'inconclusive', 'DNS_INCONCLUSIVE'], [$r->severity, $r->outcome, $r->code]);
        $this->assertTrue($r->detail['disagreement']);
        $this->assertSame('timeout', $r->detail['resolvers']['8.8.8.8']['status']);
    }

    public function test_one_resolver_silent_but_others_resolving_is_ok_with_disagreement_recorded(): void
    {
        $this->dns->set('ok.example', ['93.184.216.34']);
        $this->dns->rcode('ok.example', 'TIMEOUT', '9.9.9.9');
        [$r] = $this->dnsProbe()->probe('ok.example');
        $this->assertSame('DNS_OK', $r->code);
        $this->assertTrue($r->detail['disagreement']);
    }

    public function test_mixed_rcodes_are_inconclusive(): void
    {
        $this->dns->set('split.example', ['93.184.216.34']);
        $this->dns->rcode('split.example', 'NXDOMAIN', '1.1.1.1');
        [$r] = $this->dnsProbe()->probe('split.example');
        $this->assertSame('DNS_INCONCLUSIVE', $r->code);
    }

    public function test_servfail_records_a_dnssec_hint_but_does_not_claim_a_cause(): void
    {
        $this->dns->rcode('signed.example', 'SERVFAIL');
        $this->dns->zone['cd']['signed.example']['A'] = [['type' => 'A', 'ttl' => 60, 'data' => '93.184.216.34']];
        [$r] = $this->dnsProbe()->probe('signed.example');
        $this->assertSame('DNS_SERVFAIL', $r->code);
        $this->assertTrue($r->detail['answers_with_checking_disabled']);
    }

    public function test_all_resolvers_silent_is_a_probe_error_not_a_site_failure(): void
    {
        $this->dns->rcode('any.example', 'TIMEOUT');
        [$r] = $this->dnsProbe()->probe('any.example');
        $this->assertSame(['unknown', 'probe_error', 'DNS_PROBE_ERROR'], [$r->severity, $r->outcome, $r->code]);
    }

    public function test_any_private_answer_blocks_the_target_and_is_never_returned(): void // §17.2
    {
        $this->dns->set('rebind.example', ['93.184.216.34', '10.0.0.5']);
        [$r, $ips] = $this->dnsProbe()->probe('rebind.example');
        $this->assertSame(['probe_blocked', 'DNS_PRIVATE_ADDRESS'], [$r->outcome, $r->code]);
        $this->assertSame([], $ips);
        $this->assertNotContains('10.0.0.5', $r->detail['addresses'], 'private addresses are not even stored');
    }

    public function test_cloud_metadata_ip_literal_is_blocked(): void // AC-AST-001.2 at probe time
    {
        [$r, $ips] = $this->dnsProbe()->probe('169.254.169.254');
        $this->assertSame('DNS_PRIVATE_ADDRESS', $r->code);
        $this->assertSame([], $ips);
    }

    // ------------------------------------------------------------------ HTTP
    public function test_http_ok_with_timing_and_no_body_kept(): void
    {
        $this->http->routes['https://shop.example/'] = 200;
        $r = $this->httpProbe()->probe('https', 'shop.example', 443, '/', ['93.184.216.34']);
        $this->assertSame(['ok', 'HTTP_OK'], [$r->severity, $r->code]);
        $this->assertSame(['https://shop.example/', '93.184.216.34'], $this->http->connections[0]);
        $this->assertArrayNotHasKey('body', $r->detail);
    }

    public function test_http_status_classification(): void
    {
        $cases = [503 => ['critical', 'HTTP_503'], 500 => ['critical', 'HTTP_500'], 520 => ['critical', 'HTTP_5XX'],
            404 => ['critical', 'HTTP_404'], 403 => ['warning', 'HTTP_403'], 401 => ['warning', 'HTTP_401'],
            429 => ['unknown', 'HTTP_429'], 418 => ['warning', 'HTTP_4XX'], 204 => ['ok', 'HTTP_OK']];
        foreach ($cases as $status => [$sev, $code]) {
            $this->http->routes['https://s.example/'] = $status;
            $r = $this->httpProbe()->probe('https', 's.example', 443, '/', ['93.184.216.34']);
            $this->assertSame([$sev, $code], [$r->severity, $r->code], "status {$status}");
        }
    }

    public function test_network_errors_are_target_failures_with_distinct_codes(): void
    {
        foreach (['HTTP_TIMEOUT', 'HTTP_CONNECTION_REFUSED', 'HTTP_TLS_ERROR', 'HTTP_EMPTY_RESPONSE'] as $code) {
            $this->http->routes['https://s.example/'] = $code;
            $r = $this->httpProbe()->probe('https', 's.example', 443, '/', ['93.184.216.34']);
            $this->assertSame(['critical', 'target_failure', $code], [$r->severity, $r->outcome, $r->code]);
        }
    }

    public function test_redirect_to_private_endpoint_is_blocked_before_connecting(): void // AC-MON-001.3
    {
        $this->http->routes['https://shop.example/'] = [302, 'http://internal.shop.example/admin?token=secret'];
        $this->dns->set('internal.shop.example', ['192.168.1.10']);
        $r = $this->httpProbe()->probe('https', 'shop.example', 443, '/', ['93.184.216.34']);
        $this->assertSame(['probe_blocked', 'HTTP_REDIRECT_BLOCKED'], [$r->outcome, $r->code]);
        $this->assertCount(1, $this->http->connections, 'never connected to the redirect target');
        $json = json_encode($r->detail);
        $this->assertStringNotContainsString('secret', $json, 'query strings are never stored');
        $this->assertStringNotContainsString('192.168.1.10', $json);
    }

    public function test_redirect_to_metadata_ip_literal_is_blocked(): void
    {
        $this->http->routes['https://shop.example/'] = [301, 'http://169.254.169.254/latest/meta-data/'];
        $r = $this->httpProbe()->probe('https', 'shop.example', 443, '/', ['93.184.216.34']);
        $this->assertSame('HTTP_REDIRECT_BLOCKED', $r->code);
        $this->assertCount(1, $this->http->connections);
    }

    public function test_dns_rebinding_between_hops_is_refused(): void // AC-MON-006.1
    {
        // First answer public (DNS check), the redirect hop to the same name then rebinds to loopback.
        $this->http->routes['https://shop.example/'] = [302, '/login'];
        $this->dns->sequence['shop.example|A'] = [[['type' => 'A', 'ttl' => 0, 'data' => '127.0.0.1']]];
        $r = $this->httpProbe()->probe('https', 'shop.example', 443, '/', ['93.184.216.34']);
        $this->assertSame('HTTP_REDIRECT_BLOCKED', $r->code);
        $this->assertCount(1, $this->http->connections);
        $this->assertSame('93.184.216.34', $this->http->connections[0][1], 'pinned to the checked address');
    }

    public function test_redirect_to_disallowed_port_is_blocked(): void
    {
        $this->http->routes['https://shop.example/'] = [302, 'https://shop.example:22/'];
        $r = $this->httpProbe()->probe('https', 'shop.example', 443, '/', ['93.184.216.34']);
        $this->assertSame('HTTP_REDIRECT_BLOCKED', $r->code);
    }

    public function test_redirect_loop_stops_after_ten(): void
    {
        $this->dns->set('a.example', ['93.184.216.34']);
        $this->http->routes['https://a.example/'] = [302, 'https://a.example/x'];
        $this->http->routes['https://a.example/x'] = [302, 'https://a.example/'];
        $r = $this->httpProbe()->probe('https', 'a.example', 443, '/', ['93.184.216.34']);
        $this->assertSame('HTTP_TOO_MANY_REDIRECTS', $r->code);
        $this->assertCount(11, $this->http->connections);
    }

    public function test_relative_and_cross_host_redirects_are_followed_safely(): void
    {
        $this->dns->set('www.shop.example', ['93.184.216.35']);
        $this->http->routes['http://shop.example/'] = [301, 'https://www.shop.example/'];
        $this->http->routes['https://www.shop.example/'] = [302, 'home'];
        $this->http->routes['https://www.shop.example/home'] = 200;
        $r = $this->httpProbe()->probe('http', 'shop.example', 80, '/', ['93.184.216.34']);
        $this->assertSame('HTTP_OK', $r->code);
        $this->assertSame('93.184.216.35', $this->http->connections[1][1]);
        $this->assertCount(3, $r->detail['chain']);
    }

    public function test_https_to_http_downgrade_is_a_warning(): void
    {
        $this->dns->set('shop.example', ['93.184.216.34']);
        $this->http->routes['https://shop.example/'] = [301, 'http://shop.example/'];
        $this->http->routes['http://shop.example/'] = 200;
        $r = $this->httpProbe()->probe('https', 'shop.example', 443, '/', ['93.184.216.34']);
        $this->assertSame(['warning', 'HTTP_HTTPS_DOWNGRADE'], [$r->severity, $r->code]);
    }

    public function test_location_parsing_rejects_credentials_and_odd_schemes(): void
    {
        $this->assertNull(HttpProbe::resolveLocation('https://a.example/', 'javascript:alert(1)'));
        $this->assertNull(HttpProbe::resolveLocation('https://a.example/', 'https://user:pw@b.example/'));
        $this->assertNull(HttpProbe::resolveLocation('https://a.example/', "https://b.example/\r\nX: y"));
        $this->assertSame(['https', 'b.example', 443, '/p?q=1'], HttpProbe::resolveLocation('https://a.example/', '//b.example/p?q=1'));
        $this->assertSame('https://a.example/x?…', HttpProbe::redact('https://a.example/x?token=abc'));
    }

    // ------------------------------------------------------------------ TLS
    private function tlsProbe(int $now = 0): TlsProbe
    {
        return new TlsProbe($this->tls, $now ? fn () => $now : null);
    }

    public function test_expired_certificate_is_critical_and_recorded(): void // AC-MON-002.1
    {
        $this->tls->hosts['shop.example'] = ['verified' => false, 'verify_error' => 'certificate has expired',
            'cert' => FakeTlsInspector::cert(['not_after' => time() - 3600])];
        $r = $this->tlsProbe()->probe('shop.example', 443, ['93.184.216.34']);
        $this->assertSame(['critical', 'TLS_EXPIRED'], [$r->severity, $r->code]);
        $this->assertSame(str_repeat('ab', 32), $r->detail['sha256']);
        $this->assertArrayHasKey('not_after', $r->detail);
    }

    public function test_certificate_failures_are_explained(): void
    {
        $this->tls->hosts['www.shop.example'] = ['verified' => false, 'cert' => FakeTlsInspector::cert(['sans' => ['shop.example']])];
        $this->assertSame('TLS_HOSTNAME_MISMATCH', $this->tlsProbe()->probe('www.shop.example', 443, ['1.2.3.4'])->code);
        $this->tls->hosts['self.example'] = ['verified' => false, 'cert' => FakeTlsInspector::cert(['sans' => ['self.example'], 'self_signed' => true])];
        $this->assertSame('TLS_SELF_SIGNED', $this->tlsProbe()->probe('self.example', 443, ['1.2.3.4'])->code);
        $this->tls->hosts['chain.example'] = ['verified' => false, 'cert' => FakeTlsInspector::cert(['sans' => ['chain.example']])];
        $this->assertSame('TLS_UNTRUSTED_CHAIN', $this->tlsProbe()->probe('chain.example', 443, ['1.2.3.4'])->code);
        $this->tls->hosts['future.example'] = ['verified' => false, 'cert' => FakeTlsInspector::cert(['sans' => ['future.example'], 'not_before' => time() + 86400])];
        $this->assertSame('TLS_NOT_YET_VALID', $this->tlsProbe()->probe('future.example', 443, ['1.2.3.4'])->code);
        $this->tls->hosts['down.example'] = 'TLS_TIMEOUT';
        $this->assertSame('TLS_TIMEOUT', $this->tlsProbe()->probe('down.example', 443, ['1.2.3.4'])->code);
    }

    public function test_expiry_thresholds(): void
    {
        $now = 1_800_000_000;
        $at = fn (int $days) => ['verified' => true, 'cert' => FakeTlsInspector::cert(['sans' => ['e.example'], 'not_before' => $now - 86400, 'not_after' => $now + $days * 86400 + 60])];
        $this->tls->hosts['e.example'] = $at(30);
        $this->assertSame('TLS_OK', $this->tlsProbe($now)->probe('e.example', 443, ['1.2.3.4'])->code);
        $this->tls->hosts['e.example'] = $at(10);
        $this->assertSame(['warning', 'TLS_EXPIRING'], [($r = $this->tlsProbe($now)->probe('e.example', 443, ['1.2.3.4']))->severity, $r->code]);
        $this->tls->hosts['e.example'] = $at(1);
        $this->assertSame(['critical', 'TLS_EXPIRING_CRITICAL'], [($r = $this->tlsProbe($now)->probe('e.example', 443, ['1.2.3.4']))->severity, $r->code]);
    }

    public function test_wildcard_matching_follows_rfc6125(): void
    {
        $this->assertTrue(TlsProbe::matches('www.shop.example', ['*.shop.example'], null));
        $this->assertFalse(TlsProbe::matches('shop.example', ['*.shop.example'], null));
        $this->assertFalse(TlsProbe::matches('a.b.shop.example', ['*.shop.example'], null));
        $this->assertFalse(TlsProbe::matches('shop.example', ['other.example'], 'shop.example'), 'CN ignored when SANs exist');
        $this->assertTrue(TlsProbe::matches('shop.example', [], 'shop.example'));
    }

    // ------------------------------------------------------------- cycle
    private function runner(?FakeProbeHealth $h = null, array $delays = [0, 0]): CycleRunner
    {
        $dns = $this->dnsProbe();
        return new CycleRunner($dns, new TlsProbe($this->tls), new HttpProbe($this->http, $dns), $h ?? new FakeProbeHealth(), $delays, fn () => null);
    }

    private function target(array $over = []): object
    {
        return (object) array_merge(['scheme' => 'https', 'host' => 'shop.example', 'port' => 443, 'path' => '/', 'state' => 'active'], $over);
    }

    public function test_retries_inside_a_cycle_produce_one_reading(): void // AC-MON-005.3
    {
        $this->dns->set('shop.example', ['93.184.216.34']);
        $this->http->sequence['https://shop.example/'] = ['HTTP_TIMEOUT', 'HTTP_TIMEOUT', 200];
        $out = $this->runner()->run($this->target());
        $http = $out['readings'][2];
        $this->assertSame(['HTTP_OK', 3], [$http->code, $http->attempts]);
        $this->assertCount(3, $out['readings']);
    }

    public function test_retries_are_bounded(): void
    {
        $this->dns->set('shop.example', ['93.184.216.34']);
        $this->http->routes['https://shop.example/'] = 'HTTP_TIMEOUT';
        $http = $this->runner()->run($this->target())['readings'][2];
        $this->assertSame(['HTTP_TIMEOUT', 3], [$http->code, $http->attempts]);
        $this->assertCount(3, $this->http->connections);
    }

    public function test_broken_probe_never_marks_a_site_down(): void // §17.1
    {
        $h = new FakeProbeHealth();
        $h->ok = false;
        $out = $this->runner($h)->run($this->target());
        foreach ($out['readings'] as $r) $this->assertSame(['unknown', 'probe_error', 'PROBE_UNAVAILABLE'], [$r->severity, $r->outcome, $r->code]);
        $this->assertSame([], $this->http->connections);
    }

    public function test_pending_target_is_activated_or_rejected_by_validation(): void // §14.1
    {
        $this->dns->set('shop.example', ['93.184.216.34']);
        $this->http->routes['https://shop.example/'] = 200;
        $this->assertSame('active', $this->runner()->run($this->target(['state' => 'pending_validation']))['state']);
        $this->dns->set('intranet.example', ['10.1.2.3']);
        $out = $this->runner()->run($this->target(['host' => 'intranet.example', 'state' => 'pending_validation']));
        $this->assertSame('rejected', $out['state']);
        $this->assertSame(['DNS_PRIVATE_ADDRESS', 'TLS_NOT_CHECKED', 'HTTP_NOT_CHECKED'], array_map(fn ($r) => $r->code, $out['readings']));
    }

    public function test_every_emittable_problem_code_has_a_guide(): void // KB-002
    {
        $lib = json_decode(file_get_contents(__DIR__ . '/../../../packages/core/issues/issues.json'), true)['issues'];
        $src = '';
        foreach (glob(__DIR__ . '/../../app/Monitoring/{,*/}*.php', GLOB_BRACE) as $f) $src .= file_get_contents($f);
        preg_match_all("/'((?:DNS|HTTP|TLS|PROBE|MONITOR)_[A-Z0-9_]+)'/", $src, $m);
        $codes = array_diff(array_unique($m[1]), ['DNS_OK', 'HTTP_OK', 'TLS_OK']);
        $this->assertGreaterThan(30, count($codes));
        foreach ($codes as $c) $this->assertArrayHasKey($c, $lib, "no guide for {$c}");
    }

    public function test_issue_library_entries_are_complete(): void // AC-KB-002.1, AC-KB-001.1
    {
        $lib = json_decode(file_get_contents(__DIR__ . '/../../../packages/core/issues/issues.json'), true);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\.\d+$/', $lib['version']);
        $this->assertGreaterThanOrEqual(40, count($lib['issues']));
        foreach ($lib['issues'] as $code => $i) {
            foreach (['title', 'meaning', 'next'] as $k) $this->assertNotEmpty($i[$k] ?? null, "{$code}.{$k}");
            $this->assertNotEmpty($i['causes'], "{$code}.causes");
            $this->assertNotEmpty($i['checks'], "{$code}.checks");
            $this->assertContains($i['severity'], ['ok', 'warning', 'critical', 'unknown', 'not_applicable']);
        }
        foreach (['DNS_SERVFAIL', 'DNS_NXDOMAIN', 'TLS_EXPIRED', 'TLS_HOSTNAME_MISMATCH', 'HTTP_403', 'HTTP_503', 'HTTP_TIMEOUT', 'MONITOR_STALE'] as $c) {
            $this->assertArrayHasKey($c, $lib['issues'], "AC-KB-001.1 needs {$c}");
        }
        $titles = array_map(fn ($i) => $i['title'], $lib['issues']);
        $this->assertSame(count($titles), count(array_unique($titles)), 'every guide is distinct');
    }
}
