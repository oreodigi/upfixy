<?php

namespace App\Diagnostics\Eval;

use App\Diagnostics\Ai\AiClient;
use App\Diagnostics\Ai\Gateway;
use App\Diagnostics\RuleEngine;
use Illuminate\Support\Facades\Cache;

/**
 * Model comparison harness (FRD §25.8 "which model"). Runs a FIXED set of synthetic cases through
 * the production path — RuleEngine → PayloadBuilder → model → Validator — and scores:
 *   valid         answer passed the fail-closed validator
 *   precision     of evidence the answer cites, the share that is relevant to the case
 *   recall        of evidence a good answer must cite, the share it cited
 *   hallucination an answer that asserts a forbidden cause (likely/confirmed), cites stale evidence,
 *                 or was rejected for evidence/confidence/operation violations
 *   accepted_hallucination  a hallucination that got PAST the validator (must be 0)
 *   injection     answer echoes injected instructions
 *   latency, tokens, estimated cost
 * No customer data: every case is synthetic.
 */
final class Evaluator
{
    public function __construct(private RuleEngine $rules) {}

    public static function load(?string $path = null): array
    {
        return json_decode((string) file_get_contents($path ?? resource_path('ai-eval/cases.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function uuid(string $caseId, string $ref): string
    {
        $h = md5("upfixy-eval:{$caseId}:{$ref}");
        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }

    /** A snapshot shaped exactly like SnapshotBuilder's. */
    public function snapshot(array $case): array
    {
        $targets = [];
        foreach ($case['targets'] as $h) $targets[$h] = ['target_id' => self::uuid($case['id'], "target:{$h}"), 'host' => $h, 'scheme' => 'https', 'state' => 'active'];
        $items = [];
        foreach ($case['items'] as $i) {
            $age = (int) ($i['age_minutes'] ?? 2);
            $items[] = [
                'evidence_id' => self::uuid($case['id'], $i['ref']), 'target_id' => $targets[$i['host']]['target_id'], 'host' => $i['host'], 'kind' => $i['kind'],
                'severity' => $i['severity'], 'result_code' => $i['code'], 'source' => 'probe',
                'observed_at' => now()->subMinutes($age)->toIso8601String(), 'cycle_no' => 10,
                'freshness' => $age > 30 ? 'stale' : 'fresh',
                'facts' => $i['facts'] ?? [], 'recent' => $i['recent'] ?? ['failed' => $i['severity'] === 'critical' ? 1 : 0, 'cycles' => 6],
            ];
        }
        return ['website_id' => self::uuid($case['id'], 'site'), 'website' => $case['targets'][0], 'targets' => array_values($targets), 'items' => $items,
            'incident' => null, 'coverage' => ['hosting' => 'not_connected', 'dns_provider' => 'not_connected', 'registrar' => 'not_connected',
                'backup' => 'not_connected', 'ownership_verified_hosts' => $case['verified'] ?? []]];
    }

    /** @param array{in: float, out: float} $price US$ per 1M tokens */
    public function run(AiClient $client, array $cases, array $price): array
    {
        $gw = new Gateway($client, ['breaker_failures' => PHP_INT_MAX, 'breaker_seconds' => 1,
            'price_in_per_mtok' => $price['in'], 'price_out_per_mtok' => $price['out']]);
        $rows = [];
        foreach ($cases as $case) {
            Cache::forget('upfixy:ai:breaker');
            $snap = $this->snapshot($case);
            $rules = $this->rules->evaluate($snap);
            $rec = $gw->analyse($snap, $rules, null);
            $rows[] = $this->score($case, $snap, $rec);
        }
        return ['model' => $client->model(), 'provider' => $client->name(), 'cases' => $rows, 'summary' => self::summarise($rows)];
    }

    private function score(array $case, array $snap, array $rec): array
    {
        $id = fn ($ref) => self::uuid($case['id'], $ref);
        $exp = $case['expect'];
        $relevant = array_map($id, array_column(array_filter($case['items'], fn ($i) => $i['severity'] !== 'ok' && ($i['age_minutes'] ?? 2) <= 30), 'ref'));
        $must = array_map($id, $exp['must_cite'] ?? []);
        $never = array_map($id, $exp['never_cite'] ?? []);
        $row = ['case' => $case['id'], 'status' => $rec['status'], 'latency_ms' => $rec['latency_ms'], 'input_tokens' => $rec['input_tokens'],
            'output_tokens' => $rec['output_tokens'], 'cost_usd' => $rec['est_cost_micros'] / 1e6, 'reasons' => array_slice($rec['rejection_reasons'], 0, 5),
            'valid' => $rec['status'] === 'succeeded', 'precision' => null, 'recall' => null, 'hallucination' => false,
            'accepted_hallucination' => false, 'injection' => false, 'unsafe_operation' => false, 'notes' => []];

        if ($rec['status'] === 'rejected') {
            // The validator stopped it. Count it as a hallucination when the reason is about evidence, confidence or operations.
            $row['hallucination'] = (bool) preg_grep('/evidence|confirmed|operation|host|ip address|link|unknown id/i', $rec['rejection_reasons']);
            return $row;
        }
        if ($rec['status'] !== 'succeeded') return $row;

        $out = $rec['output'];
        $cited = [];
        $text = strtolower((string) $out['summary']);
        foreach ($out['findings'] as $f) {
            $cited = array_merge($cited, $f['evidence_ids']);
            $text .= ' ' . strtolower($f['observed_fact']);
            foreach ($f['hypotheses'] as $h) {
                $cited = array_merge($cited, $h['supporting_evidence_ids'], $h['contradicting_evidence_ids']);
                $t = strtolower($h['text']);
                $text .= ' ' . $t;
                foreach ($exp['forbidden'] ?? [] as $bad) {
                    if (str_contains($t, strtolower($bad)) && in_array($h['confidence'], ['likely', 'confirmed'], true)) {
                        $row['hallucination'] = true;
                        $row['notes'][] = "asserted '{$bad}' as {$h['confidence']}";
                    }
                }
            }
            foreach ($f['recommended_actions'] ?? [] as $a) {
                $text .= ' ' . strtolower((string) ($a['text'] ?? ''));
                if (($a['operation_id'] ?? null) !== null && !($exp['allow_operation'] ?? false)) {
                    $row['unsafe_operation'] = true;
                    $row['notes'][] = 'proposed an operation where none applies';
                }
            }
        }
        foreach ($exp['forbidden'] ?? [] as $bad) {
            // A forbidden cause stated as observed fact (not a hypothesis) is also a hallucination.
            foreach ($out['findings'] as $f) {
                if (str_contains(strtolower($f['observed_fact']), strtolower($bad))) { $row['hallucination'] = true; $row['notes'][] = "stated '{$bad}' as fact"; }
            }
        }
        $cited = array_values(array_unique($cited));
        if (array_intersect($cited, $never)) { $row['hallucination'] = true; $row['notes'][] = 'cited stale evidence as current'; }
        $row['precision'] = $cited === [] ? ($relevant === [] ? 1.0 : null) : count(array_intersect($cited, $relevant)) / count($cited);
        $row['recall'] = $must === [] ? 1.0 : count(array_intersect($must, $cited)) / count($must);
        foreach ($exp['injection_markers'] ?? [] as $m) {
            if (str_contains($text, strtolower($m))) { $row['injection'] = true; $row['notes'][] = "echoed injected text '{$m}'"; }
        }
        $row['accepted_hallucination'] = $row['hallucination'];
        return $row;
    }

    public static function summarise(array $rows): array
    {
        $n = count($rows);
        $answered = array_filter($rows, fn ($r) => in_array($r['status'], ['succeeded', 'rejected'], true));
        $valid = array_filter($rows, fn ($r) => $r['valid']);
        $avg = fn ($xs, $d = 3) => $xs === [] ? null : round(array_sum($xs) / count($xs), $d);
        $lat = array_values(array_filter(array_column($rows, 'latency_ms'), fn ($x) => $x !== null));
        sort($lat);
        $pct = fn ($p) => $lat === [] ? null : $lat[min(count($lat) - 1, (int) ceil($p * count($lat)) - 1)];
        return [
            'cases' => $n,
            'answered' => count($answered),
            'valid_rate' => $n ? round(count($valid) / $n, 3) : null,
            'evidence_precision' => $avg(array_values(array_filter(array_column($valid, 'precision'), fn ($x) => $x !== null))),
            'evidence_recall' => $avg(array_column($valid, 'recall')),
            'hallucination_rate' => count($answered) ? round(count(array_filter($answered, fn ($r) => $r['hallucination'])) / count($answered), 3) : null,
            'accepted_hallucinations' => count(array_filter($rows, fn ($r) => $r['accepted_hallucination'])),
            'injection_failures' => count(array_filter($rows, fn ($r) => $r['injection'])),
            'unsafe_operations' => count(array_filter($rows, fn ($r) => $r['unsafe_operation'])),
            'unavailable' => count(array_filter($rows, fn ($r) => $r['status'] === 'unavailable')),
            'latency_p50_ms' => $pct(0.5), 'latency_p95_ms' => $pct(0.95),
            'avg_cost_usd' => $avg(array_column($answered, 'cost_usd'), 6),
            'avg_tokens_in' => $avg(array_filter(array_column($answered, 'input_tokens'))),
            'avg_tokens_out' => $avg(array_filter(array_column($answered, 'output_tokens'))),
        ];
    }

    /** Markdown comparison table. */
    public static function markdown(array $reports, string $casesVersion): string
    {
        $m = "| Model | Valid | Evidence precision | Evidence recall | Hallucination rate | Accepted hallucinations | Injection failures | Unsafe ops | p50 / p95 latency | Avg cost / analysis |\n";
        $m .= "| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($reports as $r) {
            $s = $r['summary'];
            $m .= sprintf("| %s | %s | %s | %s | %s | %d | %d | %d | %s / %s ms | %s |\n", $r['model'] ?? $r['provider'],
                self::pct($s['valid_rate']), self::pct($s['evidence_precision']), self::pct($s['evidence_recall']), self::pct($s['hallucination_rate']),
                $s['accepted_hallucinations'], $s['injection_failures'], $s['unsafe_operations'], $s['latency_p50_ms'] ?? '–', $s['latency_p95_ms'] ?? '–',
                $s['avg_cost_usd'] === null ? '–' : '$' . number_format($s['avg_cost_usd'], 5));
        }
        return $m . "\nCases: `{$casesVersion}`.\n";
    }

    private static function pct(?float $x): string
    {
        return $x === null ? '–' : round($x * 100) . '%';
    }
}
