<?php

namespace App\Jobs;

use App\Diagnostics\Ai\Gateway;
use App\Diagnostics\RuleEngine;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Layer C, run after the HTTP response is sent. Reads the stored run as the
 * requesting user (RLS), calls the gateway OUTSIDE any transaction (no
 * database locks held during a slow model call), then stores the outcome.
 * Idempotent: a run gets at most one AI analysis.
 */
class AnalyzeDiagnosis
{
    use Dispatchable, Queueable;

    public function __construct(public string $runId, public string $userId, public string $workspaceId) {}

    public function handle(Gateway $gateway): void
    {
        $load = function () use ($gateway) {
            return DB::transaction(function () use ($gateway) {
                TenantContext::apply($this->userId, $this->workspaceId);
                $run = DB::selectOne('SELECT id, snapshot FROM diagnostic_runs WHERE id = ?', [$this->runId]);
                $done = DB::selectOne('SELECT 1 AS x FROM ai_analyses WHERE run_id = ?', [$this->runId]);
                $rules = DB::select('SELECT finding_key, observed_fact, evidence_ids, hypothesis, supporting_evidence_ids, confidence FROM findings WHERE run_id = ? AND source = ?', [$this->runId, 'rules']);
                // Budget is read here, under RLS for this workspace (AC-DX-006.2).
                $budget = $gateway->enabled() ? $gateway->overBudget($this->workspaceId) : null;
                return [$run, $done, $rules, $budget];
            });
        };
        try {
            [$run, $done, $rows, $budget] = $load();
        } finally {
            TenantContext::clear();
        }
        if (!$run || $done) return;
        $snapshot = json_decode($run->snapshot, true);
        $pg = fn (string $a) => $a === '{}' ? [] : explode(',', trim($a, '{}'));
        $hostOf = [];
        foreach ($snapshot['items'] as $i) $hostOf[$i['evidence_id']] = $i['host'];
        $rules = [
            'findings' => array_map(fn ($r) => ['finding_key' => $r->finding_key, 'observed_fact' => $r->observed_fact, 'evidence_ids' => $pg($r->evidence_ids),
                'hypothesis' => $r->hypothesis, 'supporting_evidence_ids' => $pg($r->supporting_evidence_ids), 'confidence' => $r->confidence,
                'host' => $hostOf[$pg($r->evidence_ids)[0] ?? ''] ?? ''], $rows),
            'missing' => $snapshot['rules']['missing'] ?? [], 'skipped' => $snapshot['rules']['skipped'] ?? [],
        ];

        try {
            $a = $gateway->analyse($snapshot, $rules, $budget);
        } catch (Throwable $e) {
            $a = ['status' => 'unavailable', 'provider' => 'unknown', 'model' => null, 'payload' => null, 'payload_hash' => null, 'output' => null,
                'rejection_reasons' => ['internal error'], 'input_tokens' => null, 'output_tokens' => null, 'est_cost_micros' => 0, 'latency_ms' => null];
        }
        try {
            DB::transaction(function () use ($a) {
                TenantContext::apply($this->userId, $this->workspaceId);
                if (DB::selectOne('SELECT 1 AS x FROM ai_analyses WHERE run_id = ?', [$this->runId])) return;
                DB::insert(
                    'INSERT INTO ai_analyses(workspace_id, run_id, status, provider, model, prompt_version, schema_version, payload, payload_hash,
                                             output, rejection_reasons, input_tokens, output_tokens, est_cost_micros, latency_ms)
                     VALUES (?, ?, ?::ai_status, ?, ?, ?, ?, ?::jsonb, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?)',
                    [$this->workspaceId, $this->runId, $a['status'], $a['provider'], $a['model'], \App\Diagnostics\Ai\Contract::PROMPT_VERSION,
                        \App\Diagnostics\Ai\Contract::SCHEMA_VERSION, $a['payload'] ? json_encode($a['payload']) : null, $a['payload_hash'],
                        $a['output'] ? json_encode($a['output']) : null, json_encode($a['rejection_reasons']),
                        $a['input_tokens'], $a['output_tokens'], $a['est_cost_micros'], $a['latency_ms']]);
            });
        } finally {
            TenantContext::clear();
        }
    }
}
