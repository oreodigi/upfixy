<?php

namespace App\Diagnostics\Ai;

/**
 * Server-side validation of a model answer (AC-DX-005.x, AC-DX-006.1).
 * Fails CLOSED: any breach rejects the whole answer and the report falls back
 * to measured findings. Checks, in order:
 *   - exact schema: types, required keys, no extra keys, enums, size limits;
 *   - every cited evidence ID exists in THIS run's payload (AC-DX-005.2);
 *   - "confirmed" only where deterministic rules confirmed a cause (AI-SEC-04);
 *   - repair candidates name an allowlisted operation, nothing else (AI-SEC-05);
 *   - no links, markup, code, secrets or real hostnames/IPs leaking through text.
 */
final class Validator
{
    /** @var string[] */
    private array $errors = [];

    /** @return array{ok: bool, output: ?array, errors: string[]} */
    public function validate(mixed $out, array $payload): array
    {
        $this->errors = [];
        $known = array_flip(array_column($payload['evidence'], 'evidence_id'));
        $confirmedOk = array_flip($payload['evidence_ids_with_confirmed_causes']);
        $L = Contract::LIMITS;

        if (!is_array($out)) return $this->fail('not a JSON object');
        $this->keys($out, ['summary', 'findings', 'missing_information'], 'root');
        $this->text($out['summary'] ?? null, 'summary', $L['summary']);
        $this->list($out['findings'] ?? null, 'findings', $L['findings']);
        $this->list($out['missing_information'] ?? null, 'missing_information', $L['unknowns']);
        foreach ((array) ($out['missing_information'] ?? []) as $i => $t) $this->text($t, "missing_information[{$i}]", $L['text']);

        foreach ((array) ($out['findings'] ?? []) as $n => $f) {
            $p = "findings[{$n}]";
            if (!is_array($f)) { $this->errors[] = "{$p}: not an object"; continue; }
            $this->keys($f, ['finding_id', 'evidence_ids', 'observed_fact', 'hypotheses', 'recommended_actions', 'unknowns', 'human_review_required'], $p);
            $this->text($f['finding_id'] ?? null, "{$p}.finding_id", 80);
            $this->text($f['observed_fact'] ?? null, "{$p}.observed_fact", $L['text']);
            if (!is_bool($f['human_review_required'] ?? null)) $this->errors[] = "{$p}.human_review_required: not a boolean";
            $this->ids($f['evidence_ids'] ?? null, "{$p}.evidence_ids", $known, true);
            $this->list($f['unknowns'] ?? null, "{$p}.unknowns", $L['unknowns']);
            foreach ((array) ($f['unknowns'] ?? []) as $i => $t) $this->text($t, "{$p}.unknowns[{$i}]", $L['text']);

            $this->list($f['hypotheses'] ?? null, "{$p}.hypotheses", $L['hypotheses']);
            foreach ((array) ($f['hypotheses'] ?? []) as $i => $h) {
                $q = "{$p}.hypotheses[{$i}]";
                if (!is_array($h)) { $this->errors[] = "{$q}: not an object"; continue; }
                $this->keys($h, ['text', 'supporting_evidence_ids', 'contradicting_evidence_ids', 'confidence'], $q);
                $this->text($h['text'] ?? null, "{$q}.text", $L['text']);
                $this->ids($h['supporting_evidence_ids'] ?? null, "{$q}.supporting_evidence_ids", $known, true);
                $this->ids($h['contradicting_evidence_ids'] ?? null, "{$q}.contradicting_evidence_ids", $known, false);
                $c = $h['confidence'] ?? null;
                if (!in_array($c, ['confirmed', 'likely', 'possible', 'unknown'], true)) {
                    $this->errors[] = "{$q}.confidence: invalid";
                } elseif ($c === 'confirmed') {
                    foreach ((array) ($h['supporting_evidence_ids'] ?? []) as $e) {
                        if (!isset($confirmedOk[$e])) { $this->errors[] = "{$q}: 'confirmed' without a rule-confirmed cause for {$e}"; break; }
                    }
                }
            }

            $this->list($f['recommended_actions'] ?? null, "{$p}.recommended_actions", $L['actions']);
            foreach ((array) ($f['recommended_actions'] ?? []) as $i => $a) {
                $q = "{$p}.recommended_actions[{$i}]";
                if (!is_array($a)) { $this->errors[] = "{$q}: not an object"; continue; }
                $this->keys($a, ['text', 'kind', 'operation_id'], $q);
                $this->text($a['text'] ?? null, "{$q}.text", $L['text']);
                $kind = $a['kind'] ?? null;
                if (!in_array($kind, ['check', 'contact_provider', 'request_quote', 'repair_candidate'], true)) $this->errors[] = "{$q}.kind: invalid";
                $op = $a['operation_id'] ?? null;
                if ($kind === 'repair_candidate') {
                    if (!in_array($op, Contract::OPERATIONS, true)) $this->errors[] = "{$q}.operation_id: not an allowlisted operation";
                    elseif (!$this->dnsEvidence($f['evidence_ids'] ?? [], $payload)) $this->errors[] = "{$q}: repair candidate without DNS evidence";
                } elseif ($op !== null) {
                    $this->errors[] = "{$q}.operation_id: only repair candidates may name an operation";
                }
            }
        }
        return $this->errors === [] ? ['ok' => true, 'output' => $out, 'errors' => []] : ['ok' => false, 'output' => null, 'errors' => array_slice($this->errors, 0, 20)];
    }

    private function fail(string $e): array
    {
        return ['ok' => false, 'output' => null, 'errors' => [$e]];
    }

    private function keys(array $o, array $want, string $p): void
    {
        $have = array_keys($o);
        foreach (array_diff($want, $have) as $k) $this->errors[] = "{$p}: missing {$k}";
        foreach (array_diff($have, $want) as $k) $this->errors[] = "{$p}: unexpected key " . substr((string) $k, 0, 40);
    }

    private function list(mixed $v, string $p, int $max): void
    {
        if (!is_array($v) || !array_is_list($v)) { $this->errors[] = "{$p}: not a list"; return; }
        if (count($v) > $max) $this->errors[] = "{$p}: more than {$max} items";
    }

    private function ids(mixed $v, string $p, array $known, bool $nonEmpty): void
    {
        $this->list($v, $p, Contract::LIMITS['ids']);
        if (!is_array($v)) return;
        if ($nonEmpty && $v === []) $this->errors[] = "{$p}: must cite evidence";
        foreach ($v as $id) {
            if (!is_string($id) || !isset($known[$id])) { $this->errors[] = "{$p}: unknown evidence id " . substr((string) json_encode($id), 0, 60); }
        }
    }

    private function text(mixed $v, string $p, int $max): void
    {
        if (!is_string($v) || trim($v) === '') { $this->errors[] = "{$p}: empty or not text"; return; }
        if (mb_strlen($v) > $max) $this->errors[] = "{$p}: longer than {$max} characters";
        $checks = [
            'contains a link' => '#(?:https?|ftp)://|www\.[a-z0-9-]+\.[a-z]#i',
            'contains markup or code' => '/<[a-z\/!]|```|\{\{|\}\}|<\?/i',
            'contains something that looks like a secret' => '/\b(?:upfx_|sk-[A-Za-z0-9]|AKIA[0-9A-Z]{8}|ghp_|xox[bp]-|-----BEGIN)|\b[A-Za-z0-9_\-]{40,}\b/',
            'contains an email address' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            'contains an IP address' => '/\b\d{1,3}(?:\.\d{1,3}){3}\b/',
            'contains control characters' => '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
        ];
        foreach ($checks as $why => $re) {
            if (preg_match($re, $v)) { $this->errors[] = "{$p}: {$why}"; return; }
        }
    }

    private function dnsEvidence(array $ids, array $payload): bool
    {
        foreach ($payload['evidence'] as $e) {
            if (in_array($e['evidence_id'], $ids, true) && $e['check'] === 'dns') return true;
        }
        return false;
    }
}
