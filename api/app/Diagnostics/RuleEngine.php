<?php

namespace App\Diagnostics;

use App\Monitoring\IssueLibrary;

/**
 * DX-002 deterministic diagnosis (Layer B). The only component allowed to say
 * "confirmed". It separates (§18.1, §25.1):
 *   - observations: measured facts with evidence IDs, always confirmed;
 *   - hypotheses: candidate causes, each with its own confidence;
 *   - missing evidence: what Upfixy could not see and how to unlock it.
 * A measured symptom never becomes a confirmed cause without evidence that
 * proves the cause itself (AC-DX-002.2, AC-DX-001.1).
 */
final class RuleEngine
{
    public const VERSION = 'rules-2026-10-18.1';

    /** @return array{findings: array<int, array>, missing: array<int, array>, skipped: array<int, array>, human_review_required: bool} */
    public function evaluate(array $snap): array
    {
        $findings = [];
        $missing = [];
        $items = $snap['items'];
        $byTarget = [];
        foreach ($items as $i) $byTarget[$i['target_id']][$i['kind']] = $i;

        foreach ($items as $i) {
            if ($i['freshness'] !== 'fresh') {
                $missing['stale:' . $i['target_id'] . ':' . $i['kind']] = ['key' => 'stale_reading', 'host' => $i['host'], 'check' => $i['kind'],
                    'detail' => "The latest {$i['kind']} reading for {$i['host']} is old, so it is not used as evidence.",
                    'next_step' => 'Use "Check now" for a fresh reading.'];
                continue;
            }
            if (!in_array($i['severity'], ['warning', 'critical'], true)) continue;
            foreach ($this->rules($i, $byTarget[$i['target_id']] ?? []) as $f) $findings[] = $f;
            foreach ($this->missingFor($i, $snap['coverage']) as $k => $m) $missing[$k] = $m;
        }

        // Unknowns that block deeper findings, even when nothing is failing.
        if (($snap['coverage']['ownership_verified_hosts'] ?? []) === []) {
            $missing['ownership'] = ['key' => 'ownership_unverified', 'detail' => 'Ownership is not verified, so only public checks were used.',
                'next_step' => 'Verify ownership (DNS TXT or HTML file) to allow private checks.'];
        }

        $skipped = [];
        foreach (['hosting' => 'Hosting account checks (resource limits, error logs, AutoSSL)', 'dns_provider' => 'DNS zone records at your DNS provider',
                  'registrar' => 'Domain registration and expiry', 'backup' => 'Backup status'] as $k => $label) {
            if (($snap['coverage'][$k] ?? null) === 'not_connected') $skipped[] = ['check' => $k, 'label' => $label, 'reason' => 'skipped — not connected'];
        }

        $review = false;
        foreach ($findings as $f) {
            if ($f['severity'] === 'critical' && $f['hypothesis'] !== null && in_array($f['confidence'], ['possible', 'unknown'], true)) $review = true;
        }
        return ['findings' => $findings, 'missing' => array_values($missing), 'skipped' => $skipped, 'human_review_required' => $review];
    }

    /** @return array<int, array> observation finding + zero or more hypothesis findings */
    private function rules(array $i, array $sameTarget): array
    {
        $code = $i['result_code'];
        $issue = IssueLibrary::get($code);
        $ev = [$i['evidence_id']];
        $recent = $i['recent'];
        $repeat = $recent['failed'] >= 2;
        $when = substr($i['observed_at'], 0, 16) . 'Z';
        $observed = $this->observedFact($i);
        $actions = $this->actions($issue);

        $out = [$this->finding($i, "observed:{$code}", $observed, $ev, null, 'confirmed', $actions)];
        $hyp = function (string $key, string $text, string $confidence, array $support = [], array $contra = []) use ($i, $observed, $ev, $actions) {
            return $this->finding($i, $key, $observed, $ev, $text, $confidence, $actions, $support ?: $ev, $contra);
        };

        switch (true) {
            case in_array($code, ['HTTP_500', 'HTTP_502', 'HTTP_503', 'HTTP_504', 'HTTP_5XX'], true):
                // AC-DX-001.1 / AC-DX-002.2: the status is confirmed; any server-side cause stays Unknown without hosting evidence.
                $out[] = $hyp('cause:server_side', 'A problem on the web server or in the website software (for example a resource limit, maintenance mode or an application error).', 'unknown');
                break;
            case in_array($code, ['HTTP_TIMEOUT', 'HTTP_CONNECTION_REFUSED', 'HTTP_CONNECTION_RESET', 'HTTP_EMPTY_RESPONSE'], true):
                $dnsOk = ($sameTarget['dns']['result_code'] ?? null) === 'DNS_OK';
                $out[] = $hyp('cause:server_unreachable', 'The server is down, overloaded, or a firewall blocks connections.', 'unknown',
                    $dnsOk ? [$i['evidence_id'], $sameTarget['dns']['evidence_id']] : []);
                break;
            case $code === 'HTTP_TLS_ERROR' && isset($sameTarget['tls']) && $sameTarget['tls']['severity'] === 'critical':
                // Both checks on the same address failing on TLS: the certificate problem explains the HTTPS error.
                $out[] = $hyp('cause:certificate', "The secure-connection error is explained by the certificate problem ({$sameTarget['tls']['result_code']}).", 'likely',
                    [$i['evidence_id'], $sameTarget['tls']['evidence_id']]);
                break;
            case $code === 'TLS_EXPIRED':
                $out[] = $hyp('cause:certificate_not_renewed', 'The certificate was not renewed before its expiry date, so browsers block visitors with a security warning.', 'confirmed');
                $out[] = $hyp('cause:renewal_failure', 'Automatic renewal is failing or was never set up.', 'unknown');
                break;
            case in_array($code, ['TLS_EXPIRING', 'TLS_EXPIRING_CRITICAL'], true):
                $out[] = $hyp('cause:renewal_pending', 'Automatic renewal has not replaced the certificate yet; it may still succeed, or it may be failing.', 'unknown');
                break;
            case $code === 'TLS_HOSTNAME_MISMATCH':
                // The SAN list in the evidence proves the certificate does not cover this name.
                $out[] = $hyp('cause:name_not_covered', 'The installed certificate does not include this exact address.', 'confirmed');
                $out[] = $hyp('cause:wrong_server_or_cert', 'The certificate was issued without this name, or DNS points this name at a server set up for another site.', 'possible');
                break;
            case in_array($code, ['TLS_SELF_SIGNED', 'TLS_UNTRUSTED_CHAIN'], true):
                $out[] = $hyp('cause:untrusted_certificate', 'A default or incomplete certificate is installed instead of one from a trusted issuer.', 'possible');
                break;
            case $code === 'DNS_NXDOMAIN':
                $out[] = $hyp('cause:name_missing', 'The domain registration lapsed, or no record exists for this exact name.', $repeat ? 'possible' : 'unknown');
                break;
            case $code === 'DNS_SERVFAIL':
                $cd = $i['facts']['answers_with_checking_disabled'] ?? null;
                $all = count(array_filter($i['facts']['resolver_rcodes'] ?? [], fn ($r) => $r === 'SERVFAIL')) >= 2;
                if ($cd === true) {
                    // AC-DX-002.1: resolver-side evidence only. "Confirmed" would also need authoritative-server evidence (DNS connector, M4).
                    $out[] = $hyp('cause:dnssec_validation', 'DNSSEC validation is failing: resolvers can read the records only when signature checking is turned off.',
                        $repeat && $all ? 'likely' : 'possible');
                } else {
                    $out[] = $hyp('cause:nameservers', 'The domain\'s nameservers are not answering correctly.', $repeat && $all ? 'possible' : 'unknown');
                }
                break;
            case $code === 'HTTP_403':
                $out[] = $hyp('cause:checker_blocked', 'A firewall or security plugin blocks automated checks like ours, while visitors can still see the site.', 'possible');
                $out[] = $hyp('cause:all_blocked', 'The server forbids all visitors (for example wrong permissions).', 'possible');
                break;
            case $code === 'HTTP_TOO_MANY_REDIRECTS':
                $out[] = $hyp('cause:redirect_rules', 'Redirect rules send visitors in a loop (for example http/https or www rules fighting each other).', 'confirmed');
                break;
        }
        return $out;
    }

    private function finding(array $i, string $key, string $observed, array $ev, ?string $hypothesis, string $confidence, array $actions, array $support = [], array $contra = []): array
    {
        return [
            'finding_key' => $key . ':' . $i['target_id'],
            'host' => $i['host'], 'kind' => $i['kind'], 'result_code' => $i['result_code'], 'severity' => $i['severity'],
            'observed_fact' => $observed,
            'evidence_ids' => $ev,
            'hypothesis' => $hypothesis,
            'supporting_evidence_ids' => $hypothesis ? $support : [],
            'contradicting_evidence_ids' => $contra,
            'confidence' => $confidence,
            // DX-004: provider coverage needs a linked contract (M6). Until then nothing is called covered.
            'responsibility' => 'undetermined',
            'recommended_actions' => $hypothesis === null ? $actions : [],
        ];
    }

    private function observedFact(array $i): string
    {
        $f = $i['facts'];
        $title = IssueLibrary::title($i['result_code']);
        $times = $i['recent']['cycles'] > 0 ? " ({$i['recent']['failed']} of the last {$i['recent']['cycles']} checks failed)" : '';
        $extra = match (true) {
            isset($f['status']) && $i['kind'] === 'http' => " — HTTP {$f['status']}",
            isset($f['days_left']) && $i['kind'] === 'tls' => isset($f['not_after']) ? ' — valid until ' . substr($f['not_after'], 0, 10) : '',
            default => '',
        };
        return "{$title} on {$i['host']}{$extra}, observed " . substr($i['observed_at'], 0, 16) . " UTC{$times}.";
    }

    private function actions(?array $issue): array
    {
        if (!$issue) return [];
        $out = array_map(fn ($c) => ['kind' => 'check', 'text' => $c], $issue['checks']);
        $out[] = ['kind' => 'next_step', 'text' => $issue['next']];
        return $out;
    }

    private function missingFor(array $i, array $coverage): array
    {
        $code = $i['result_code'];
        $out = [];
        $hosting = str_starts_with($code, 'HTTP_5') || in_array($code, ['HTTP_TIMEOUT', 'HTTP_CONNECTION_REFUSED', 'HTTP_CONNECTION_RESET',
            'HTTP_EMPTY_RESPONSE', 'HTTP_403', 'TLS_EXPIRED', 'TLS_EXPIRING', 'TLS_EXPIRING_CRITICAL', 'TLS_SELF_SIGNED', 'TLS_UNTRUSTED_CHAIN'], true);
        if ($hosting && ($coverage['hosting'] ?? null) === 'not_connected') {
            $out['hosting'] = ['key' => 'hosting_not_connected', 'detail' => 'No hosting connection: server logs, resource limits and AutoSSL status are unavailable.',
                'next_step' => 'Connect the hosting account (read-only) to check the server side.'];
        }
        if (str_starts_with($code, 'DNS_') && ($coverage['dns_provider'] ?? null) === 'not_connected') {
            $out['dns_provider'] = ['key' => 'dns_provider_not_connected', 'detail' => 'No DNS provider connection: zone records and authoritative answers are unavailable.',
                'next_step' => 'Connect the DNS provider (read-only) to compare the records directly.'];
        }
        if ($code === 'DNS_NXDOMAIN' && ($coverage['registrar'] ?? null) === 'not_connected') {
            $out['registrar'] = ['key' => 'registrar_not_connected', 'detail' => 'Registration status and expiry are unknown.',
                'next_step' => 'Check the renewal date with your registrar.'];
        }
        return $out;
    }
}
