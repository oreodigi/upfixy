<?php

namespace App\Diagnostics\Ai;

/**
 * Builds the ONLY thing sent to the AI provider (§25.3, AI-SEC-02/03):
 * one website's snapshot, minimised and pseudonymised. Hostnames become
 * "site-A", "site-B"…, other names and IPs are dropped, free text that came
 * from the website (headers, certificate names) is cut short, stripped of
 * control characters and labelled untrusted. No tokens, emails, account IDs,
 * client names or other websites ever enter the payload.
 */
final class PayloadBuilder
{
    /** @var array<string, string> real host => pseudonym */
    private array $hosts = [];

    public function build(array $snapshot, array $rules): array
    {
        $this->hosts = [];
        foreach ($snapshot['targets'] as $t) $this->alias($t['host']);

        $evidence = [];
        foreach ($snapshot['items'] as $i) {
            if ($i['freshness'] !== 'fresh') continue; // stale readings are never evidence
            $evidence[] = [
                'evidence_id' => $i['evidence_id'],
                'target' => $this->alias($i['host']),
                'check' => $i['kind'],
                'result_code' => $i['result_code'],
                'severity' => $i['severity'],
                'source' => $i['source'],
                'observed_at' => $i['observed_at'],
                'recent_cycles' => $i['recent'],
                'facts' => $this->facts($i['kind'], $i['facts']),
            ];
        }
        $confirmedCauses = [];
        $ruleFindings = [];
        foreach ($rules['findings'] as $f) {
            $ruleFindings[] = [
                'finding_key' => preg_replace('/:[0-9a-f-]{36}$/', '', $f['finding_key']),
                'target' => $this->alias($f['host']),
                'observed_fact' => $this->scrub($f['observed_fact']),
                'hypothesis' => $f['hypothesis'],
                'confidence' => $f['confidence'],
                'evidence_ids' => $f['evidence_ids'],
            ];
            if ($f['hypothesis'] !== null && $f['confidence'] === 'confirmed') {
                foreach ($f['supporting_evidence_ids'] as $e) $confirmedCauses[$e] = true;
            }
        }
        return [
            'schema_version' => Contract::SCHEMA_VERSION,
            'rule_version' => \App\Diagnostics\RuleEngine::VERSION,
            'evidence' => $evidence,
            'rule_findings' => $ruleFindings,
            'evidence_ids_with_confirmed_causes' => array_keys($confirmedCauses),
            'missing_evidence' => array_map(fn ($m) => ['key' => $m['key'], 'detail' => $this->scrub($m['detail'])], $rules['missing']),
            'not_connected' => array_map(fn ($s) => $s['check'], $rules['skipped']),
            'allowed_operations' => Contract::OPERATIONS,
        ];
    }

    /** @return array<string, string> pseudonym => real host, to map AI text back for display */
    public function aliases(): array
    {
        return array_flip($this->hosts);
    }

    private function alias(string $host): string
    {
        return $this->hosts[$host] ??= 'site-' . chr(65 + count($this->hosts) % 26) . (count($this->hosts) >= 26 ? (string) intdiv(count($this->hosts), 26) : '');
    }

    private function facts(string $kind, array $f): array
    {
        $out = [];
        foreach ($f as $k => $v) {
            if (in_array($k, ['subject_cn', 'sans'], true)) {
                // Certificate names: only whether they cover OUR hosts, never other names.
                $names = (array) $v;
                $out[$k] = array_values(array_map(fn ($n) => $this->hosts[strtolower((string) $n)] ?? (str_starts_with((string) $n, '*.') ? 'wildcard-name' : 'other-name'), array_slice($names, 0, 10)));
                if ($k === 'subject_cn') $out[$k] = $out[$k][0] ?? null;
                continue;
            }
            if (in_array($k, ['issuer_cn', 'content_type'], true)) {
                $out['untrusted_text'][$k] = $this->untrusted((string) $v);
                continue;
            }
            $out[$k] = is_string($v) ? $this->scrub($v) : $v;
        }
        return $out;
    }

    /** Site-controlled text: short, printable, no markup, clearly labelled as data. */
    private function untrusted(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '', $s);
        $s = preg_replace('/[<>{}\[\]`]/', '', $s);
        return substr($s, 0, 60);
    }

    /** Replace real hostnames and drop IP addresses in rule text. */
    private function scrub(string $s): string
    {
        foreach ($this->hosts as $real => $alias) $s = str_ireplace($real, $alias, $s);
        $s = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $s);
        // IPv6: a hex/colon token with three or more colons or a "::" (times like 07:50:00 have only two).
        return preg_replace_callback('/(?<![\w:])[0-9a-f:]{3,}(?![\w:])/i',
            fn ($m) => (substr_count($m[0], ':') >= 3 || str_contains($m[0], '::')) ? '[ip]' : $m[0], $s);
    }
}
