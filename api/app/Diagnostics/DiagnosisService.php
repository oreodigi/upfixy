<?php

namespace App\Diagnostics;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * DX-001 "Analyze & Find Solution": eligibility → collect → evaluate → store.
 * Runs inside the caller's request transaction and RLS context. The AI step
 * (if configured) runs afterwards, outside any transaction, and only adds an
 * analysis next to the deterministic findings; it can never change them.
 */
final class DiagnosisService
{
    public function __construct(private SnapshotBuilder $snapshots, private RuleEngine $rules) {}

    /**
     * @return array{run_id: ?string, reused: bool, error: ?string, ai: bool}
     */
    public function start(string $websiteId): array
    {
        $snap = $this->snapshots->build($websiteId);
        if (!$snap) return ['run_id' => null, 'reused' => false, 'error' => 'not_found', 'ai' => false];
        if (!collect($snap['items'])->contains(fn ($i) => $i['freshness'] === 'fresh')) {
            return ['run_id' => null, 'reused' => false, 'error' => 'no_fresh_evidence', 'ai' => false];
        }
        // Repeated clicks on the same evidence return the same report: no duplicate (billable) analyses.
        $existing = DB::selectOne(
            "SELECT id FROM diagnostic_runs WHERE website_id = ? AND snapshot_hash = ? AND created_at > now() - make_interval(mins => ?)
              ORDER BY created_at DESC LIMIT 1", [$websiteId, $snap['hash'], config('upfixy.diagnostics.reuse_minutes')]);
        if ($existing) return ['run_id' => $existing->id, 'reused' => true, 'error' => null, 'ai' => false];

        $result = $this->rules->evaluate($snap);
        $snap['rules'] = ['missing' => $result['missing'], 'skipped' => $result['skipped'], 'human_review_required' => $result['human_review_required']];
        $private = collect($snap['items'])->contains(fn ($i) => $i['source'] !== 'public');
        $run = DB::selectOne(
            "INSERT INTO diagnostic_runs(workspace_id, website_id, requested_by, status, rule_version, snapshot, snapshot_hash, private_evidence)
             VALUES (?, ?, ?, 'evaluating', ?, ?::jsonb, ?, ?) RETURNING id",
            [TenantContext::workspaceId(), $websiteId, TenantContext::userId(), RuleEngine::VERSION, json_encode($snap), $snap['hash'], $private]);
        foreach ($result['findings'] as $f) {
            DB::insert(
                "INSERT INTO findings(workspace_id, run_id, source, finding_key, observed_fact, evidence_ids, hypothesis, supporting_evidence_ids,
                                      contradicting_evidence_ids, confidence, responsibility, recommended_actions, human_review_required)
                 VALUES (?, ?, 'rules', ?, ?, ?::uuid[], ?, ?::uuid[], ?::uuid[], ?::finding_confidence, ?::responsibility, ?::jsonb, ?)",
                [TenantContext::workspaceId(), $run->id, $f['finding_key'], $f['observed_fact'], self::arr($f['evidence_ids']), $f['hypothesis'],
                    self::arr($f['supporting_evidence_ids']), self::arr($f['contradicting_evidence_ids']), $f['confidence'], $f['responsibility'],
                    json_encode($f['recommended_actions']), $f['severity'] === 'critical' && $f['hypothesis'] !== null && in_array($f['confidence'], ['possible', 'unknown'], true)]);
        }
        DB::update("UPDATE diagnostic_runs SET status = 'completed', completed_at = now() WHERE id = ?", [$run->id]);
        return ['run_id' => $run->id, 'reused' => false, 'error' => null, 'ai' => true];
    }

    public static function arr(array $ids): string
    {
        foreach ($ids as $id) if (!preg_match('/^[0-9a-f-]{36}$/', $id)) throw new \InvalidArgumentException('bad evidence id');
        return '{' . implode(',', $ids) . '}';
    }
}
