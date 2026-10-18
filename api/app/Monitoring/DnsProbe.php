<?php

namespace App\Monitoring;

use App\Domain\UrlGuard;
use App\Monitoring\Dns\DnsClient;
use App\Monitoring\Dns\DnsTimeout;
use Throwable;

/**
 * MON-003: asks several independent resolvers. Agreement is required before
 * a failure is called a failure; disagreement is recorded as such and never
 * turned into a cause (AC-MON-003.1). Addresses are checked against the SSRF
 * blocklist here, once, and only checked addresses are handed to HTTP/TLS.
 */
final class DnsProbe
{
    /** @param string[] $resolvers */
    public function __construct(private DnsClient $dns, private array $resolvers) {}

    /** @return array{0: Reading, 1: string[]} reading and the validated public addresses */
    public function probe(string $host): array
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            return UrlGuard::isBlockedAddress($bare)
                ? [Reading::unknown('dns', 'probe_blocked', 'DNS_PRIVATE_ADDRESS', ['addresses' => ['(blocked)']]), []]
                : [new Reading('dns', 'not_applicable', 'ok', 'DNS_NOT_APPLICABLE', ['note' => 'IP address target']), [$bare]];
        }

        $per = [];
        foreach ($this->resolvers as $r) {
            $per[$r] = $this->addresses($r, $host);
        }
        $answered = array_filter($per, fn ($x) => $x['status'] === 'answered');
        $timeouts = count($per) - count($answered);
        $detail = ['resolvers' => array_map(fn ($x) => array_diff_key($x, ['addresses_raw' => 1]), $per)];

        if ($answered === []) {
            return [Reading::unknown('dns', 'probe_error', 'DNS_PROBE_ERROR', $detail), []];
        }
        $rcodes = array_values(array_unique(array_map(fn ($x) => $x['rcode'], $answered)));
        $addresses = array_values(array_unique(array_merge(...array_map(fn ($x) => $x['addresses_raw'], array_values($answered)))));
        $detail['dnssec'] = count(array_filter($answered, fn ($x) => $x['ad'])) === count($answered) ? 'validated' : 'not_validated';

        if (count($rcodes) > 1) {
            $detail['disagreement'] = true;
            return [Reading::unknown('dns', 'inconclusive', 'DNS_INCONCLUSIVE', $detail), []];
        }
        $rcode = $rcodes[0];
        $consensusFailure = $rcode !== 'NOERROR' || $addresses === [];
        if ($timeouts > 0) {
            $detail['disagreement'] = true;
            // One resolver silent, the others report a failure: not enough to assert it.
            if ($consensusFailure) return [Reading::unknown('dns', 'inconclusive', 'DNS_INCONCLUSIVE', $detail), []];
        }
        if ($rcode === 'NXDOMAIN') return [Reading::fail('dns', 'DNS_NXDOMAIN', $detail), []];
        if ($rcode === 'SERVFAIL') {
            // DNSSEC hint only: the same question with checking disabled. Diagnostics decide (AC-DX-002.1).
            try {
                $cd = $this->dns->query($this->resolvers[0], $host, 'A', true);
                $detail['answers_with_checking_disabled'] = $cd['rcode'] === 'NOERROR' && $this->extract($cd['answers']) !== [];
            } catch (Throwable) {
                $detail['answers_with_checking_disabled'] = null;
            }
            return [Reading::fail('dns', 'DNS_SERVFAIL', $detail), []];
        }
        if ($rcode !== 'NOERROR') return [Reading::fail('dns', 'DNS_ERROR', $detail + ['rcode' => $rcode]), []];
        if ($addresses === []) return [Reading::fail('dns', 'DNS_NO_ADDRESS', $detail), []];

        $public = array_values(array_filter($addresses, fn ($ip) => !UrlGuard::isBlockedAddress($ip)));
        $detail['addresses'] = array_map(fn ($ip) => UrlGuard::isBlockedAddress($ip) ? '(blocked)' : $ip, $addresses);
        if (count($public) !== count($addresses)) {
            // Any private answer means we refuse to connect at all (§17.2): a mixed set is a rebinding risk.
            return [Reading::unknown('dns', 'probe_blocked', 'DNS_PRIVATE_ADDRESS', $detail), []];
        }
        $detail['records'] = $this->records($host);
        return [Reading::ok('dns', 'DNS_OK', $detail), $public];
    }

    /**
     * Resolve a redirect hop's hostname: first resolver that answers.
     * @return array{0: string, 1: string[]} result code and addresses (empty unless DNS_OK)
     */
    public function resolve(string $host): array
    {
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP)) {
            return UrlGuard::isBlockedAddress($bare) ? ['DNS_PRIVATE_ADDRESS', []] : ['DNS_OK', [$bare]];
        }
        foreach ($this->resolvers as $r) {
            $x = $this->addresses($r, $host);
            if ($x['status'] !== 'answered') continue;
            if ($x['rcode'] === 'NXDOMAIN') return ['DNS_NXDOMAIN', []];
            if ($x['rcode'] !== 'NOERROR' || $x['addresses_raw'] === []) return ['DNS_NO_ADDRESS', []];
            foreach ($x['addresses_raw'] as $ip) {
                if (UrlGuard::isBlockedAddress($ip)) return ['DNS_PRIVATE_ADDRESS', []];
            }
            return ['DNS_OK', $x['addresses_raw']];
        }
        return ['DNS_PROBE_ERROR', []];
    }

    /** A decides the verdict; AAAA only adds addresses and never fails the check on its own. */
    private function addresses(string $resolver, string $host): array
    {
        $out = ['status' => 'answered', 'rcode' => null, 'ad' => false, 'ms' => 0, 'addresses_raw' => [], 'cname' => []];
        try {
            $a = $this->dns->query($resolver, $host, 'A');
        } catch (DnsTimeout) {
            return ['status' => 'timeout'] + $out;
        } catch (Throwable $e) {
            return ['status' => 'error', 'error' => substr($e->getMessage(), 0, 120)] + $out;
        }
        $out['rcode'] = $a['rcode'];
        $out['ad'] = $a['ad'];
        $out['ms'] = $a['ms'];
        $answers = $a['answers'];
        if ($a['rcode'] === 'NOERROR') {
            try {
                $aaaa = $this->dns->query($resolver, $host, 'AAAA');
                if ($aaaa['rcode'] === 'NOERROR') $answers = array_merge($answers, $aaaa['answers']);
                $out['ms'] += $aaaa['ms'];
            } catch (Throwable) {
                // IPv6 lookup failure is not a site failure.
            }
        }
        $out['addresses_raw'] = array_values(array_unique($this->extract($answers)));
        foreach ($answers as $x) if ($x['type'] === 'CNAME') $out['cname'][] = $x['data'];
        $out['cname'] = array_values(array_unique($out['cname']));
        $out['addresses'] = count($out['addresses_raw']);
        return $out;
    }

    private function extract(array $answers): array
    {
        $ips = [];
        foreach ($answers as $a) if (in_array($a['type'], ['A', 'AAAA'], true)) $ips[] = $a['data'];
        return $ips;
    }

    /** Informational records (MON-003). Failures here never change the verdict. */
    private function records(string $host): array
    {
        $out = [];
        foreach (['NS', 'SOA', 'MX', 'CAA'] as $type) {
            try {
                $r = $this->dns->query($this->resolvers[0], $host, $type);
                $vals = array_values(array_filter($r['answers'], fn ($a) => $a['type'] === $type));
                $out[$type] = array_slice(array_map(fn ($a) => $a['data'], $vals), 0, 10);
            } catch (Throwable) {
                $out[$type] = null;
            }
        }
        return $out;
    }
}
