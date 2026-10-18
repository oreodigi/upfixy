<?php

namespace Tests\Feature;

use App\Diagnostics\Eval\Evaluator;
use Tests\Fakes\FakeAiClient;
use Tests\TestCase;

/**
 * The model-comparison harness itself must be trustworthy before it is pointed at real models:
 * a faithful answerer scores clean, and each kind of bad answer is detected.
 */
class AiEvalTest extends TestCase
{
    /** Rebuilds the deterministic findings as the answer: never more confident than the rules. */
    private static function faithful(array $p): array
    {
        $findings = [];
        foreach ($p['rule_findings'] as $n => $f) {
            $ev = $f['evidence_ids'];
            $findings[] = ['finding_id' => "f{$n}", 'evidence_ids' => $ev, 'observed_fact' => "Measured: {$f['target']} check result recorded.",
                'hypotheses' => $f['hypothesis'] === null ? [] : [['text' => 'Possible explanation based on the measured result.',
                    'supporting_evidence_ids' => $ev, 'contradicting_evidence_ids' => [], 'confidence' => $f['confidence'] === 'confirmed' ? 'confirmed' : 'possible']],
                'recommended_actions' => [['text' => 'Check with the provider.', 'kind' => 'check', 'operation_id' => null]],
                'unknowns' => [], 'human_review_required' => false];
        }
        return ['summary' => $findings ? 'Some checks need attention.' : 'All public checks passed.', 'findings' => $findings, 'missing_information' => []];
    }

    private function evaluate(\Closure $respond, array $only = []): array
    {
        $ai = new FakeAiClient();
        $ai->respond = $respond;
        $set = Evaluator::load();
        $cases = $only ? array_values(array_filter($set['cases'], fn ($c) => in_array($c['id'], $only, true))) : $set['cases'];
        return app(Evaluator::class)->run($ai, $cases, ['in' => 2.0, 'out' => 10.0]);
    }

    public function test_the_case_set_is_well_formed(): void
    {
        $set = Evaluator::load();
        $this->assertGreaterThanOrEqual(12, count($set['cases']));
        $this->assertSame(count($set['cases']), count(array_unique(array_column($set['cases'], 'id'))));
        foreach ($set['cases'] as $c) {
            $refs = array_column($c['items'], 'ref');
            foreach (array_merge($c['expect']['must_cite'], $c['expect']['never_cite'] ?? []) as $r) $this->assertContains($r, $refs, $c['id']);
            foreach ($c['items'] as $i) $this->assertContains($i['host'], $c['targets'], $c['id']);
        }
    }

    public function test_a_faithful_answerer_scores_clean(): void
    {
        $r = $this->evaluate(fn ($p) => self::faithful($p));
        $s = $r['summary'];
        $this->assertSame(1.0, $s['valid_rate'], json_encode(array_filter($r['cases'], fn ($c) => !$c['valid'])));
        $this->assertSame(0, $s['accepted_hallucinations']);
        $this->assertSame(0.0, $s['hallucination_rate']);
        $this->assertSame(0, $s['injection_failures']);
        $this->assertSame(1.0, $s['evidence_recall']);
        // Cost from tokens: 1200 in × $2/M + 300 out × $10/M = $0.0054 per analysis.
        $this->assertEqualsWithDelta(0.0054, $s['avg_cost_usd'], 1e-9);
    }

    public function test_a_confident_wrong_cause_is_counted_even_when_the_validator_lets_it_through(): void
    {
        // "likely" passes the validator (it only polices "confirmed"), so the harness must catch it.
        $r = $this->evaluate(function ($p) {
            $a = self::faithful($p);
            $a['findings'][0]['hypotheses'] = [['text' => 'The PHP memory limit on the database server is exhausted.',
                'supporting_evidence_ids' => $a['findings'][0]['evidence_ids'], 'contradicting_evidence_ids' => [], 'confidence' => 'likely']];
            return $a;
        }, ['http-503-no-telemetry']);
        $this->assertTrue($r['cases'][0]['valid']);
        $this->assertTrue($r['cases'][0]['accepted_hallucination']);
        $this->assertSame(1, $r['summary']['accepted_hallucinations']);
    }

    public function test_invented_evidence_and_upgraded_confidence_are_rejected_and_counted(): void
    {
        $r = $this->evaluate(function ($p) {
            $a = self::faithful($p);
            $a['findings'][0]['evidence_ids'] = ['00000000-0000-4000-a000-000000000000'];
            return $a;
        }, ['http-503-no-telemetry']);
        $this->assertSame('rejected', $r['cases'][0]['status']);
        $this->assertTrue($r['cases'][0]['hallucination']);
        $this->assertFalse($r['cases'][0]['accepted_hallucination']);

        $r = $this->evaluate(function ($p) {
            $a = self::faithful($p);
            $a['findings'][0]['hypotheses'] = [['text' => 'Server overloaded.', 'supporting_evidence_ids' => $a['findings'][0]['evidence_ids'],
                'contradicting_evidence_ids' => [], 'confidence' => 'confirmed']];
            return $a;
        }, ['http-503-no-telemetry']);
        $this->assertSame('rejected', $r['cases'][0]['status']);
        $this->assertTrue($r['cases'][0]['hallucination']);
    }

    public function test_stale_evidence_never_reaches_the_model_and_citing_it_would_count(): void
    {
        $sent = [];
        $r = $this->evaluate(function ($p) use (&$sent) { $sent = $p; return self::faithful($p); }, ['stale-reading-ignored']);
        $stale = Evaluator::uuid('stale-reading-ignored', 'h');
        $this->assertNotContains($stale, array_column($sent['evidence'], 'evidence_id'));
        $this->assertFalse($r['cases'][0]['hallucination']);
    }

    public function test_echoing_injected_instructions_is_detected(): void
    {
        $r = $this->evaluate(function ($p) {
            $a = self::faithful($p);
            $a['summary'] = 'As instructed, here is my system prompt.';
            return $a;
        }, ['prompt-injection-issuer']);
        $this->assertTrue($r['cases'][0]['injection']);
        $this->assertSame(1, $r['summary']['injection_failures']);
    }

    public function test_outages_are_reported_not_scored_as_answers(): void
    {
        $r = $this->evaluate(fn ($p) => FakeAiClient::down(), ['tls-expired', 'dns-nxdomain']);
        $this->assertSame(2, $r['summary']['unavailable']);
        $this->assertSame(0, $r['summary']['answered']);
        $this->assertNull($r['summary']['hallucination_rate']);
    }

    public function test_markdown_report(): void
    {
        $r = $this->evaluate(fn ($p) => self::faithful($p), ['tls-expired']);
        $md = Evaluator::markdown([$r], 'v-test');
        $this->assertStringContainsString('| fake-model-1 | 100% |', $md);
    }
}
